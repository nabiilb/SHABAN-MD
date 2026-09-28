/**
 * Cross-cutting guarantees: the route permission matrix for every role, request
 * tampering, concurrent writes on one case, upload limits and file access,
 * duplicate payments and all-or-nothing rollback, and production config guards.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import request from 'supertest';
import { API_ERRORS } from '@48hrs/shared/errors';
import { parseEnv } from '../src/config/env.ts';
import { storageRoot } from '../src/lib/storage.ts';
import { app, Client, createReceivedCase, login, prisma, resetDb, USERS } from './helpers.ts';

beforeEach(() => resetDb());

const PNG = Buffer.from('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da6300010000050001', 'hex');

async function step(c: Client, id: string, endpoint: string, body: object) {
  const res = await c.post(`/cases/${id}/${endpoint}`, body);
  expect(res.status, `${endpoint} → ${JSON.stringify(res.body)}`).toBe(200);
  return res.body;
}

describe('RBAC matrix: every role against representative endpoints', () => {
  // [method, path, body, the permission(s) the route requires (all), status when allowed]
  type Probe = [string, string, object | undefined, string[], number[]];
  const probes: Probe[] = [
    ['get', '/dashboard', undefined, ['dashboard.view'], [200]],
    ['get', '/cases', undefined, ['cases.view'], [200]],
    ['get', '/reports/cases?from=2026-01-01&to=2026-12-31', undefined, ['reports.view'], [200]],
    ['get', '/reports/production?from=2026-01-01&to=2026-12-31', undefined, ['reports.view'], [200]],
    ['get', '/reports/financial?from=2026-01-01&to=2026-12-31', undefined, ['reports.view', 'reports.financial'], [200]],
    ['get', '/invoices', undefined, ['invoices.view'], [200]],
    ['get', '/payments', undefined, ['payments.view'], [200]],
    ['post', '/payments', {}, ['payments.record'], [422]],
    ['post', '/invoices', {}, ['payments.record'], [422]],
    ['get', '/quality-control', undefined, ['qc.view'], [200]],
    ['get', '/deliveries', undefined, ['delivery.view'], [200]],
    ['get', '/users', undefined, ['users.view'], [200]],
    ['post', '/users', {}, ['users.manage'], [422]],
    ['put', '/roles/delivery', { permissions: 'not-a-list' }, ['roles.manage'], [422]],
    ['post', '/services', {}, ['services.manage'], [422]],
    ['get', '/activity', undefined, ['audit.view'], [200]],
    ['post', '/patients', {}, ['patients.create'], [422]],
    ['del', '/cases/case_does_not_exist', undefined, ['cases.delete'], [404]],
    ['post', '/clinics', {}, ['clinics.create'], [422]],
    ['post', '/technicians', {}, ['technicians.create'], [422]],
  ];
  const roles = Object.entries(USERS).filter(([k]) => k !== 'disabledClient' && k !== 'technician2');

  it.each(roles)('%s: allowed exactly where the role holds the permission, 403 elsewhere', async (name, email) => {
    const c = await login(email);
    const me = (await c.get('/auth/me')).body as { permissions: string[] };
    for (const [method, path, body, needs, allowed] of probes) {
      const res = await (c as unknown as Record<string, (p: string, b?: object) => Promise<{ status: number; body: unknown }>>)[method](path, body);
      const expected = needs.every((p) => me.permissions.includes(p));
      const label = `${name} ${method.toUpperCase()} ${path} → ${res.status} ${JSON.stringify(res.body)}`;
      if (expected) expect(allowed, label).toContain(res.status);
      else {
        expect(res.status, label).toBe(403);
        expect(res.body, label).toEqual({ message: 'Access restricted.' });
      }
    }
  });

  it('the matrix is not trivially all-allowed or all-denied', async () => {
    const perms = async (email: string) => ((await (await login(email)).get('/auth/me')).body as { permissions: string[] }).permissions;
    const superAdmin = await perms(USERS.superAdmin);
    const client = await perms(USERS.client);
    const tech = await perms(USERS.technician);
    expect(superAdmin).toEqual(expect.arrayContaining(['users.manage', 'roles.manage', 'reports.financial', 'payments.record', 'audit.view']));
    for (const p of ['users.view', 'payments.record', 'reports.financial', 'audit.view', 'cases.view_all']) {
      expect(client).not.toContain(p);
      expect(tech).not.toContain(p);
    }
  });

  it('every protected endpoint answers 401 without a session', async () => {
    for (const path of ['/auth/me', '/cases', '/dashboard', '/invoices', '/payments', '/reports/financial', '/users', '/search?q=a', '/notifications', '/activity']) {
      const res = await request(app).get(`/api${path}`);
      expect(res.status, path).toBe(401);
    }
  });
});

describe('request tampering is ignored or refused by the server', () => {
  it('a client cannot widen its scope with query parameters', async () => {
    const client = await login(USERS.client);
    const cases = (await client.get('/cases?clinicId=cln_banadir&perPage=100')).body;
    expect(cases.data.every((r: { clinicId: string }) => r.clinicId === 'cln_smile')).toBe(true);
    const invoices = await client.get('/invoices?clinicId=cln_banadir');
    if (invoices.status === 200) expect(invoices.body.data.every((r: { clinicId: string }) => r.clinicId === 'cln_smile')).toBe(true);
    else expect(invoices.status).toBe(403);
  });

  it('server-owned fields in the body are ignored: status, clock, price, clinic', async () => {
    const client = await login(USERS.client);
    const res = await client.post('/cases', {
      patientId: 'pat_1024', doctorId: 'doc_amina', serviceId: 'svc_zirconia', shade: 'A2', teeth: [3], priority: 'normal', instructions: '',
      status: 'delivered', total: 0, unitPrice: 0, receivedAt: '2020-01-01T00:00:00Z', dueAt: '2099-01-01T00:00:00Z', clinicId: 'cln_banadir', technicianId: 'tec_fatima',
    });
    expect(res.status, JSON.stringify(res.body)).toBe(201);
    const row = await prisma.dentalCase.findUniqueOrThrow({ where: { id: res.body.id } });
    expect(row).toMatchObject({ status: 'submitted', receivedAt: null, dueAt: null, clinicId: 'cln_smile', technicianId: null });
    expect(res.body.total).toBeGreaterThan(0);
  });

  it('privilege escalation through the users API is refused', async () => {
    const reception = await login(USERS.reception);
    const me = (await reception.get('/auth/me')).body.user;
    const self = await reception.put(`/users/${me.id}`, { name: me.name, email: me.email, role: 'super_admin', active: true });
    expect(self.status).toBe(403);
    const admin = await login(USERS.admin);
    const grant = await admin.post('/users', { name: 'Eve', email: 'eve@48hrs.lab', role: 'super_admin', password: 'Long-enough-pass-9', active: true });
    expect(grant.status).toBe(422);
    expect(grant.body.errors.role).toBeTruthy();
    expect(await prisma.user.count({ where: { email: 'eve@48hrs.lab' } })).toBe(0);
  });

  it('a technician cannot reassign or edit a case by sending the fields directly', async () => {
    const tech = await login(USERS.technician);
    const found = await prisma.dentalCase.findFirstOrThrow({ where: { technicianId: 'tec_fatima', status: 'in_production' } });
    const own = await prisma.dentalCase.update({ where: { id: found.id }, data: { status: 'assigned' } }); // a status where assigning is a valid step
    expect((await tech.patch(`/cases/${own.id}`, { technicianId: 'tec_ali' })).status).toBe(403);
    expect((await tech.post(`/cases/${own.id}/assign`, { technicianId: 'tec_ali' })).status).toBe(403);
    expect((await prisma.dentalCase.findUniqueOrThrow({ where: { id: own.id } })).technicianId).toBe('tec_fatima');
  });

  it('the payment actor is the session user, never a body field', async () => {
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception);
    const res = await reception.post('/payments', { invoiceId: c.invoice!.id, amount: 5, method: 'cash', receivedById: 'usr_khalid', receivedByName: 'Khalid' });
    expect(res.status).toBe(201);
    expect(res.body.payments[0].receivedByName).toBe('Sagal Warsame');
  });
});

describe('concurrent writes on the same case', () => {
  it('two managers assigning at once leave exactly one open assignment that matches the case', async () => {
    const reception = await login(USERS.reception);
    const [m1, m2] = await Promise.all([login(USERS.manager), login(USERS.admin)]);
    const c = await createReceivedCase(reception);
    const [a, b] = await Promise.all([m1.post(`/cases/${c.id}/assign`, { technicianId: 'tec_fatima' }), m2.post(`/cases/${c.id}/assign`, { technicianId: 'tec_ali' })]);
    expect([a.status, b.status]).toContain(200);
    for (const r of [a, b]) expect([200, 409]).toContain(r.status);
    const open = await prisma.caseAssignment.findMany({ where: { caseId: c.id, unassignedAt: null } });
    expect(open).toHaveLength(1);
    const row = await prisma.dentalCase.findUniqueOrThrow({ where: { id: c.id } });
    expect(row.technicianId).toBe(open[0].technicianId);
  });

  it('two QC results at once: one is recorded, the other gets 409', async () => {
    const reception = await login(USERS.reception);
    const manager = await login(USERS.manager);
    const tech = await login(USERS.technician);
    const qc1 = await login(USERS.qc);
    const qc2 = await login(USERS.admin);
    const c = await createReceivedCase(reception);
    await step(manager, c.id, 'assign', { technicianId: 'tec_fatima' });
    await step(tech, c.id, 'status', { status: 'in_production' });
    await step(tech, c.id, 'status', { status: 'quality_control' });
    const [a, b] = await Promise.all([
      qc1.post(`/cases/${c.id}/qc`, { result: 'pass', issues: [], notes: '' }),
      qc2.post(`/cases/${c.id}/qc`, { result: 'fail', issues: ['shade'], notes: 'Too light' }),
    ]);
    expect([a.status, b.status].sort()).toEqual([200, 409]);
    expect(await prisma.qualityCheck.count({ where: { caseId: c.id } })).toBe(1);
    const row = await prisma.dentalCase.findUniqueOrThrow({ where: { id: c.id } });
    expect(row.status).toBe(a.status === 200 ? 'ready' : 'rework');
  });
});

describe('files', () => {
  const upload = (c: Client, caseId: string) => c.agent.post(`/api/cases/${caseId}/attachments`).set('X-Requested-With', 'XMLHttpRequest');

  it('oversized uploads are refused (422) and nothing is stored', async () => {
    const reception = await login(USERS.reception);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production' } });
    const big = Buffer.concat([Buffer.from('solid big\n'), Buffer.alloc(1024 * 1024 + 10, 0x20), Buffer.from('endsolid big\n')]);
    const res = await upload(reception, c.id).attach('file', big, 'big.stl');
    expect(res.status).toBe(422);
    expect(res.body.errors.file[0]).toMatch(/1 MB/);
    expect(await prisma.caseAttachment.count({ where: { caseId: c.id, name: 'big.stl' } })).toBe(0);
  });

  it('no session: upload and download are 401; guessed storage paths are never served', async () => {
    const reception = await login(USERS.reception);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production' } });
    const up = await upload(reception, c.id).attach('file', PNG, 'x.png');
    expect(up.status).toBe(201);
    const stored = await prisma.caseAttachment.findUniqueOrThrow({ where: { id: up.body.id } });

    const anon = await request(app).post(`/api/cases/${c.id}/attachments`).set('X-Requested-With', 'XMLHttpRequest').attach('file', PNG, 'y.png');
    expect(anon.status).toBe(401);
    expect((await request(app).get(`/api/cases/${c.id}/attachments/${up.body.id}/download`)).status).toBe(401);

    for (const guess of [`/storage/uploads/${stored.storageKey}`, `/uploads/${stored.storageKey}`, `/api/uploads/${stored.storageKey}`, `/api/storage/${stored.storageKey}`, `/${stored.storageKey}`]) {
      const res = await reception.agent.get(guess);
      expect(res.status, guess).toBe(404);
    }
    expect(storageRoot).not.toContain('public');

    // Right attachment id under another case's URL, and another clinic's user: both 404.
    const other = await prisma.dentalCase.findFirstOrThrow({ where: { id: { not: c.id } } });
    expect((await reception.get(`/cases/${other.id}/attachments/${up.body.id}/download`)).status).toBe(404);
    const foreignClinic = c.clinicId === 'cln_smile' ? null : await login(USERS.client);
    if (foreignClinic) expect((await foreignClinic.get(`/cases/${c.id}/attachments/${up.body.id}/download`)).status).toBe(404);
  });

  it('path traversal in the ids is harmless', async () => {
    const reception = await login(USERS.reception);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production' } });
    for (const id of ['..%2F..%2F..%2Fetc%2Fpasswd', '%2e%2e%2f%2e%2e%2fpackage.json']) {
      const res = await reception.get(`/cases/${c.id}/attachments/${id}/download`);
      expect(res.status).toBe(404);
    }
  });
  it('STL (ASCII and binary) is accepted and served back byte-for-byte as model/stl', async () => {
    const reception = await login(USERS.reception);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production' } });
    const binary = Buffer.alloc(84 + 50);
    binary.write('binary stl header', 0);
    binary.writeUInt32LE(1, 80);
    for (const [name, bytes] of [['ascii.stl', Buffer.from('solid t\nendsolid t\n')], ['binary.stl', binary]] as const) {
      const up = await upload(reception, c.id).attach('file', bytes, name);
      expect(up.status, JSON.stringify(up.body)).toBe(201);
      expect(up.body).toMatchObject({ extension: 'stl', mimeType: 'model/stl', category: 'scan', size: bytes.length });
      const dl = await reception.agent.get(`/api/cases/${c.id}/attachments/${up.body.id}/download`).buffer(true).parse((r, cb) => {
        const chunks: Buffer[] = [];
        r.on('data', (d: Buffer) => chunks.push(d));
        r.on('end', () => cb(null, Buffer.concat(chunks)));
      });
      expect(Buffer.compare(dl.body as Buffer, bytes)).toBe(0);
      expect(dl.headers['content-type']).toBe('model/stl');
    }
  });

  it('the client-declared MIME type is never trusted: stored and served by verified content/extension', async () => {
    const reception = await login(USERS.reception);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production' } });
    const png = await upload(reception, c.id).attach('file', PNG, { filename: 'photo.png', contentType: 'text/html' });
    expect(png.status).toBe(201);
    expect(png.body.mimeType).toBe('image/png');
    const dl = await reception.get(`/cases/${c.id}/attachments/${png.body.id}/download`);
    expect(dl.headers['content-type']).toBe('image/png');
    expect(dl.headers['content-disposition']).toMatch(/^attachment;/); // never rendered inline by the browser
    expect(dl.headers['x-content-type-options']).toBe('nosniff');

    const html = await upload(reception, c.id).attach('file', Buffer.from('<!DOCTYPE html><script>alert(1)</script>'), { filename: 'scan.stl', contentType: 'model/stl' });
    expect(html.status).toBe(422);
    const exe = await upload(reception, c.id).attach('file', Buffer.from('MZ\x90\x00\x03'), { filename: 'upper.stl', contentType: 'model/stl' });
    expect(exe.status).toBe(422);
  });

  it('extensions outside the allow-list are refused, including double extensions', async () => {
    const reception = await login(USERS.reception);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production' } });
    for (const name of ['scan.stl.exe', 'page.html', 'image.svg', 'script.js', 'archive.zip', 'noextension']) {
      const res = await upload(reception, c.id).attach('file', Buffer.from('solid x\nendsolid x\n'), name);
      expect(res.status, name).toBe(422);
      expect(res.body.errors.file[0], name).toMatch(/not accepted/);
    }
    expect(await prisma.caseAttachment.count({ where: { caseId: c.id, name: { in: ['scan.stl.exe', 'page.html', 'image.svg'] } } })).toBe(0);
  });

  it('users outside the case cannot list, download or delete its files', async () => {
    const reception = await login(USERS.reception);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production', technicianId: { not: 'tec_fatima' }, clinicId: { not: 'cln_smile' } } });
    const up = await upload(reception, c.id).attach('file', PNG, 'private.png');
    expect(up.status).toBe(201);
    const tech = await login(USERS.technician); // not assigned to this case
    const client = await login(USERS.client); // another clinic
    for (const outsider of [tech, client]) {
      expect((await outsider.get(`/cases/${c.id}`)).status).toBe(404);
      expect((await outsider.get(`/cases/${c.id}/attachments/${up.body.id}/download`)).status).toBe(404);
      expect([403, 404]).toContain((await outsider.del(`/cases/${c.id}/attachments/${up.body.id}`)).status);
    }
    expect(await prisma.caseAttachment.count({ where: { id: up.body.id } })).toBe(1);
  });
});

describe('payments: duplicates, completed invoices and all-or-nothing', () => {
  it('a transaction reference can only be recorded once (422), the balance is untouched', async () => {
    const reception = await login(USERS.reception);
    const a = await createReceivedCase(reception);
    const b = await createReceivedCase(reception);
    expect((await reception.post('/payments', { invoiceId: a.invoice!.id, amount: 10, method: 'bank_transfer', reference: 'TRX-001' })).status).toBe(201);
    const dup = await reception.post('/payments', { invoiceId: b.invoice!.id, amount: 10, method: 'bank_transfer', reference: ' TRX-001 ' });
    expect(dup.status).toBe(422);
    expect(dup.body.errors.reference[0]).toMatch(/already recorded/);
    const inv = await prisma.invoice.findUniqueOrThrow({ where: { id: b.invoice!.id }, include: { payments: true } });
    expect(inv.amountPaid.toNumber()).toBe(0);
    expect(inv.payments).toHaveLength(0);
  });

  it('the same reference submitted twice at once: one payment, the other 422; ledger and case stay consistent in PostgreSQL', async () => {
    const reception = await login(USERS.reception);
    const admin = await login(USERS.admin);
    const a = await createReceivedCase(reception);
    const body = { invoiceId: a.invoice!.id, amount: 10, method: 'mobile_money', reference: 'EVC-DOUBLE-TAP' };
    const [r1, r2] = await Promise.all([reception.post('/payments', body), admin.post('/payments', body)]);
    expect([r1.status, r2.status].sort()).toEqual([201, 422]);
    const loser = r1.status === 422 ? r1 : r2;
    expect(loser.body).toEqual({ message: API_ERRORS.validation, errors: { reference: [expect.stringMatching(/^This reference is already recorded on INV-/)] } });

    const [ledger] = await prisma.$queryRaw<{ n: number; sum: number; paid: number; total: number }[]>`
      SELECT (SELECT count(*)::int FROM payments p WHERE p.reference = 'EVC-DOUBLE-TAP') AS n,
             (SELECT coalesce(sum(p.amount), 0)::float FROM payments p WHERE p."invoiceId" = i.id) AS sum,
             i."amountPaid"::float AS paid, i.total::float AS total
      FROM invoices i WHERE i.id = ${a.invoice!.id}`;
    expect(ledger).toEqual({ n: 1, sum: 10, paid: 10, total: a.total });
    const row = await prisma.dentalCase.findUniqueOrThrow({ where: { id: a.id } });
    expect(row.status).toBe('received'); // payments never move the workflow
    expect((await reception.get(`/cases/${a.id}`)).body.paymentStatus).toBe('partial');
  });

  it('the same reference raced onto two different invoices is still recorded once', async () => {
    const reception = await login(USERS.reception);
    const admin = await login(USERS.admin);
    const [a, b] = [await createReceivedCase(reception), await createReceivedCase(reception)];
    const [r1, r2] = await Promise.all([
      reception.post('/payments', { invoiceId: a.invoice!.id, amount: 5, method: 'bank_transfer', reference: 'BANK-RACE-1' }),
      admin.post('/payments', { invoiceId: b.invoice!.id, amount: 5, method: 'bank_transfer', reference: 'BANK-RACE-1' }),
    ]);
    expect([r1.status, r2.status].sort()).toEqual([201, 422]);
    const [{ n, paid }] = await prisma.$queryRaw<{ n: number; paid: number }[]>`
      SELECT (SELECT count(*)::int FROM payments WHERE reference = 'BANK-RACE-1') AS n,
             (SELECT sum("amountPaid")::float FROM invoices WHERE id IN (${a.invoice!.id}, ${b.invoice!.id})) AS paid`;
    expect({ n, paid }).toEqual({ n: 1, paid: 5 });
  });

  it('a fully paid invoice takes no more money', async () => {
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception);
    expect((await reception.post('/payments', { invoiceId: c.invoice!.id, amount: c.total, method: 'cash' })).body.status).toBe('paid');
    const more = await reception.post('/payments', { invoiceId: c.invoice!.id, amount: 1, method: 'cash' });
    expect(more.status).toBe(422);
    expect(more.body.errors.amount).toBeTruthy();
    expect(await prisma.payment.count({ where: { invoiceId: c.invoice!.id } })).toBe(1);
    expect((await reception.post('/payments', { invoiceId: 'inv_missing', amount: 1, method: 'cash' })).status).toBe(404);
  });

  it('accepting a submission with a reused payment reference rolls everything back', async () => {
    const reception = await login(USERS.reception);
    const client = await login(USERS.client);
    const paid = await createReceivedCase(reception);
    await reception.post('/payments', { invoiceId: paid.invoice!.id, amount: 5, method: 'bank_transfer', reference: 'BANK-77' });
    const sub = await client.post('/cases', { patientId: 'pat_1024', doctorId: 'doc_amina', serviceId: 'svc_zirconia', shade: 'A2', teeth: [3], priority: 'normal', instructions: '' });
    const historyBefore = await prisma.caseStatusHistory.count({ where: { caseId: sub.body.id } });
    const res = await reception.post(`/cases/${sub.body.id}/status`, { status: 'received', payment: { amount: 5, method: 'bank_transfer', reference: 'BANK-77' } });
    expect(res.status).toBe(422);
    expect(res.body.errors['payment.reference']).toBeTruthy();
    const row = await prisma.dentalCase.findUniqueOrThrow({ where: { id: sub.body.id }, include: { invoice: true } });
    expect(row).toMatchObject({ status: 'submitted', receivedAt: null, dueAt: null, invoice: null });
    expect(await prisma.caseStatusHistory.count({ where: { caseId: sub.body.id } })).toBe(historyBefore);
    expect(await prisma.payment.count({ where: { reference: 'BANK-77' } })).toBe(1);
  });
});

describe('production configuration guards', () => {
  const good = {
    NODE_ENV: 'production',
    DATABASE_URL: 'postgresql://lab:x@db.internal:5432/dental_lab',
    JWT_SECRET: 'x'.repeat(48),
    CORS_ORIGINS: 'https://lab.example.com',
    COOKIE_SECURE: 'true',
    APP_URL: 'https://lab.example.com',
    MAIL_TRANSPORT: 'smtp',
    SMTP_URL: 'smtp://user:pass@smtp.example.com:587',
    MAIL_FROM: '48HRS Dental Lab <no-reply@lab.example.com>',
    LAB_TIMEZONE: 'Africa/Mogadishu',
  };
  const fails = (over: Record<string, string | undefined>, field: string) => expect(() => parseEnv({ ...good, ...over })).toThrow(new RegExp(`${field}:`));

  it('a complete production configuration is accepted, with secure defaults', () => {
    const env = parseEnv(good);
    expect(env).toMatchObject({ COOKIE_SECURE: true, COOKIE_SAMESITE: 'lax', ACCESS_TOKEN_TTL_MINUTES: 15, SESSION_TTL_MINUTES: 480 });
  });

  it('refuses unsafe production settings', () => {
    fails({ JWT_SECRET: 'short' }, 'JWT_SECRET');
    fails({ JWT_SECRET: undefined }, 'JWT_SECRET');
    fails({ DATABASE_URL: undefined }, 'DATABASE_URL');
    fails({ COOKIE_SECURE: 'false' }, 'COOKIE_SECURE');
    fails({ CORS_ORIGINS: '' }, 'CORS_ORIGINS');
    fails({ CORS_ORIGINS: 'http://lab.example.com' }, 'CORS_ORIGINS');
    fails({ MAIL_TRANSPORT: 'log' }, 'MAIL_TRANSPORT');
    fails({ SMTP_URL: undefined }, 'SMTP_URL');
    fails({ APP_URL: 'http://localhost:5173' }, 'APP_URL');
    fails({ MAIL_FROM: undefined }, 'MAIL_FROM');
    fails({ LAB_TIMEZONE: 'Mars/Olympus' }, 'LAB_TIMEZONE');
    fails({ JWT_SECRET: 'CHANGE_ME_openssl_rand_base64_48' }, 'JWT_SECRET'); // the template value is long enough, but public
    fails({ JWT_SECRET: 'CHANGE_ME_TO_48_RANDOM_BYTES_BASE64', NODE_ENV: 'development' }, 'JWT_SECRET');
    fails({ DATABASE_URL: 'postgresql://lab_app:CHANGE_ME@db.internal:5432/dental_lab' }, 'DATABASE_URL');
    fails({ SMTP_URL: 'smtps://USER:CHANGE_ME@smtp.example.com:465' }, 'SMTP_URL');
    fails({ COOKIE_SECURE: 'false', COOKIE_SAMESITE: 'none', NODE_ENV: 'development' }, 'COOKIE_SAMESITE');
  });
});
