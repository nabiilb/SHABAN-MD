import { beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { createReceivedCase, login, prisma, resetDb, USERS, type Client } from './helpers.ts';

type Row = { id: string; caseNumber: string; status: string; priority: string; technicianId: string | null; doctorId: string; clinicId: string; receivedAt: string | null; createdAt: string; dueAt: string | null; paymentStatus: string; patient: { name: string }; total: number };
const list = async (c: Client, qs: string) => {
  const res = await c.get(`/cases?perPage=200&${qs}`);
  expect(res.status, JSON.stringify(res.body)).toBe(200);
  return res.body.data as Row[];
};

describe('create, read, update, delete', () => {
  beforeEach(() => resetDb());

  it('creates with server-side pricing, case number and a new patient', async () => {
    const reception = await login(USERS.reception);
    const res = await reception.post('/cases', {
      newPatient: { name: 'Test Patient', phone: '+252 61 111 2222' },
      doctorId: 'doc_amina',
      clinicId: 'cln_smile',
      serviceId: 'svc_emax',
      shade: 'A1',
      teeth: [9, 8, 8],
      priority: 'urgent',
      instructions: 'Rush',
      total: 1, // ignored: prices come from the catalogue and settings
    });
    expect(res.status).toBe(201);
    expect(res.body).toMatchObject({ teeth: [8, 9], units: 2, unitPrice: 25, emergencyFee: 10, total: 60, priority: 'urgent', status: 'received' });
    expect(res.body.caseNumber).toMatch(/^DL-\d{4}-\d{5}$/);
    expect(res.body.patient).toMatchObject({ name: 'Test Patient' });
    expect(res.body.patient.code).toMatch(/^PT-\d+$/);
    expect(res.body.invoice).toMatchObject({ total: 60, paid: 0, remaining: 60, status: 'unpaid' });
    expect(res.body.history).toHaveLength(1);
  });

  it('422 for missing and inconsistent fields', async () => {
    const reception = await login(USERS.reception);
    const empty = await reception.post('/cases', {});
    expect(empty.status).toBe(422);
    expect(Object.keys(empty.body.errors)).toEqual(expect.arrayContaining(['doctorId', 'serviceId', 'shade', 'priority']));
    const mismatch = await reception.post('/cases', { patientId: 'pat_1024', doctorId: 'doc_layla', clinicId: 'cln_smile', serviceId: 'svc_zirconia', shade: 'A2', teeth: [], priority: 'normal' });
    expect(mismatch.status).toBe(422);
    expect(mismatch.body.errors.doctorId[0]).toMatch(/does not belong/);
    expect(mismatch.body.errors.teeth[0]).toMatch(/at least one tooth/);
    const badTooth = await reception.post('/cases', { patientId: 'pat_1024', doctorId: 'doc_amina', clinicId: 'cln_smile', serviceId: 'svc_zirconia', shade: 'A2', teeth: [33], priority: 'normal' });
    expect(badTooth.body.errors['teeth.0']).toBeTruthy();
    const pastDue = await reception.post('/cases', { patientId: 'pat_1024', doctorId: 'doc_amina', clinicId: 'cln_smile', serviceId: 'svc_zirconia', shade: 'A2', teeth: [3], priority: 'normal', dueAt: '2001-01-01T00:00:00Z' });
    expect(pastDue.body.errors.dueAt).toBeTruthy();
  });

  it('clients can only submit for their own clinic', async () => {
    const client = await login(USERS.client);
    const res = await client.post('/cases', { patientId: 'pat_1025', doctorId: 'doc_layla', clinicId: 'cln_horizon', serviceId: 'svc_zirconia', shade: 'A2', teeth: [3], priority: 'normal', instructions: '' });
    expect(res.status).toBe(422); // their clinic is forced; Dr. Layla is not at Smile
    expect(res.body.errors.doctorId).toBeTruthy();
  });

  it('edits open cases and re-prices the invoice; closed cases are read-only', async () => {
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception);
    const res = await reception.patch(`/cases/${c.id}`, { teeth: [7, 8, 9], shade: 'B1', priority: 'urgent' });
    expect(res.status).toBe(200);
    expect(res.body).toMatchObject({ teeth: [7, 8, 9], shade: 'B1', units: 3, total: 75 });
    expect(res.body.invoice).toMatchObject({ total: 75, remaining: 75 });
    const closed = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'completed' } });
    const refused = await reception.patch(`/cases/${closed.id}`, { shade: 'A1' });
    expect(refused.status).toBe(422);
    expect(refused.body.message).toMatch(/Closed cases/);
  });

  it('deletes only cases without payments; everything linked goes with it', async () => {
    const admin = await login(USERS.admin);
    const c = await createReceivedCase(admin);
    expect((await admin.del(`/cases/${c.id}`)).status).toBe(204);
    expect(await prisma.dentalCase.findUnique({ where: { id: c.id } })).toBeNull();
    expect(await prisma.invoice.count({ where: { caseId: c.id } })).toBe(0);
    expect(await prisma.caseStatusHistory.count({ where: { caseId: c.id } })).toBe(0);
    const paid = await prisma.payment.findFirstOrThrow({ include: { invoice: true } });
    const res = await admin.del(`/cases/${paid.invoice.caseId}`);
    expect(res.status).toBe(422);
    const reception = await login(USERS.reception);
    expect((await reception.del(`/cases/${paid.invoice.caseId}`)).status).toBe(403);
  });

  it('404 for missing cases; notes are validated and stored', async () => {
    const manager = await login(USERS.manager);
    expect((await manager.get('/cases/cas_missing')).status).toBe(404);
    expect((await manager.get('/cases/cas_missing')).body).toEqual({ message: 'Resource not found.' });
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production' } });
    expect((await manager.post(`/cases/${c.id}/notes`, { text: '  ' })).status).toBe(422);
    const res = await manager.post(`/cases/${c.id}/notes`, { text: 'Check the margin' });
    expect(res.status).toBe(201);
    expect(res.body.notes.at(-1)).toMatchObject({ text: 'Check the margin', authorName: 'Omar Farah' });
    expect((await manager.get(`/cases/${c.caseNumber}`)).body.id).toBe(c.id);
  });
});

describe('list: search, filters, sorting, pagination, scope', () => {
  beforeAll(() => resetDb());

  it('filters by status, priority, technician, doctor, clinic and case type', async () => {
    const admin = await login(USERS.admin);
    const all = await list(admin, '');
    expect(all.length).toBe(await prisma.dentalCase.count());
    for (const [qs, check] of [
      ['status=in_production&status=rework', (r: Row) => ['in_production', 'rework'].includes(r.status)],
      ['priority=urgent', (r: Row) => r.priority === 'urgent'],
      ['technicianId=tec_fatima', (r: Row) => r.technicianId === 'tec_fatima'],
      ['doctorId=doc_amina', (r: Row) => r.doctorId === 'doc_amina'],
      ['clinicId=cln_banadir', (r: Row) => r.clinicId === 'cln_banadir'],
      ['openOnly=true', (r: Row) => !['delivered', 'completed', 'cancelled', 'rejected'].includes(r.status)],
    ] as const) {
      const rows = await list(admin, qs);
      expect(rows.length, qs).toBeGreaterThan(0);
      expect(rows.every(check), qs).toBe(true);
      expect(rows.length, qs).toBeLessThan(all.length);
    }
    const dentures = await list(admin, 'caseType=denture');
    expect(dentures.length).toBe(await prisma.dentalCase.count({ where: { caseType: 'denture' } }));
  });

  it('date range on received date and on the deadline', async () => {
    const admin = await login(USERS.admin);
    // Days are lab-calendar days (LAB_TIMEZONE), whatever the server's or runner's zone and time of day.
    const labDay = (d: Date | string | number) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Mogadishu' }).format(new Date(d));
    const fresh = await createReceivedCase(await login(USERS.reception));
    const today = labDay(fresh.receivedAt);
    const recent = await list(admin, `from=${today}&to=${today}`);
    expect(recent.map((r) => r.id)).toContain(fresh.id);
    expect(recent.every((r) => labDay(r.receivedAt ?? r.createdAt) === today)).toBe(true); // portal submissions: by submission date
    const yesterday = labDay(new Date(fresh.receivedAt).getTime() - 86_400_000);
    expect((await list(admin, `from=${yesterday}&to=${yesterday}`)).map((r) => r.id)).not.toContain(fresh.id);
    const dueDay = labDay(fresh.dueAt);
    const due = await list(admin, `dueFrom=${dueDay}&dueTo=${dueDay}`);
    expect(due.map((r) => r.id)).toContain(fresh.id);
    expect(due.every((r) => r.dueAt && labDay(r.dueAt) === dueDay)).toBe(true);
    expect((await admin.get('/cases?from=2026-13-40')).status).toBe(422);
  });

  it('SLA and payment filters', async () => {
    const admin = await login(USERS.admin);
    const overdue = await list(admin, 'sla=overdue');
    expect(overdue.length).toBeGreaterThan(0);
    expect(overdue.every((r) => new Date(r.dueAt!).getTime() <= Date.now())).toBe(true);
    for (const status of ['paid', 'partial', 'unpaid', 'overdue']) {
      const rows = await list(admin, `paymentStatus=${status}`);
      expect(rows.every((r) => r.paymentStatus === status), status).toBe(true);
    }
    expect((await admin.get('/cases?sla=soon')).status).toBe(422);
  });

  it('search by case number, patient name and code, doctor, clinic', async () => {
    const admin = await login(USERS.admin);
    const c = await prisma.dentalCase.findFirstOrThrow({ include: { patient: true } });
    expect((await list(admin, `search=${c.caseNumber}`)).map((r) => r.id)).toEqual([c.id]);
    expect((await list(admin, `search=${encodeURIComponent(c.patient.name)}`)).every((r) => r.patient.name === c.patient.name)).toBe(true);
    expect((await list(admin, `search=${c.patient.code}`)).length).toBeGreaterThan(0);
    expect((await list(admin, 'search=banadir')).every((r) => r.clinicId === 'cln_banadir')).toBe(true);
  });

  it('sorts and paginates', async () => {
    const admin = await login(USERS.admin);
    const byDue = await list(admin, 'sort=dueAt&dir=asc');
    const dues = byDue.filter((r) => r.dueAt).map((r) => r.dueAt!);
    expect(dues).toEqual([...dues].sort());
    const byTotal = await list(admin, 'sort=total&dir=desc');
    expect(byTotal.map((r) => r.total)).toEqual([...byTotal.map((r) => r.total)].sort((a, b) => b - a));
    const p1 = await admin.get('/cases?perPage=10&page=1');
    const p2 = await admin.get('/cases?perPage=10&page=2');
    expect(p1.body.meta).toMatchObject({ page: 1, perPage: 10, total: await prisma.dentalCase.count() });
    expect(p2.body.data[0].id).not.toBe(p1.body.data[0].id);
    const beyond = await admin.get('/cases?perPage=10&page=999');
    expect(beyond.body.meta.page).toBe(beyond.body.meta.lastPage);
    expect((await admin.get('/cases?sort=nonsense')).status).toBe(422);
  });

  it('row scope: technicians see their cases, clients their clinic, 404 outside', async () => {
    const tech = await login(USERS.technician);
    const mine = await list(tech, '');
    expect(mine.length).toBe(await prisma.dentalCase.count({ where: { technicianId: 'tec_fatima' } }));
    expect(mine.every((r) => r.technicianId === 'tec_fatima')).toBe(true);
    const client = await login(USERS.client);
    const theirs = await list(client, '');
    expect(theirs.every((r) => r.clinicId === 'cln_smile')).toBe(true);
    const other = await prisma.dentalCase.findFirstOrThrow({ where: { clinicId: { not: 'cln_smile' } } });
    expect((await client.get(`/cases/${other.id}`)).status).toBe(404);
    const counts = (await client.get('/cases/counts')).body;
    expect(counts.inProduction).toBe(await prisma.dentalCase.count({ where: { clinicId: 'cln_smile', status: { in: ['assigned', 'in_production', 'rework'] } } }));
  });

  it('users without cases.view get 403', async () => {
    const admin = await login(USERS.superAdmin);
    const role = (await admin.get('/roles')).body.find((r: { key: string }) => r.key === 'delivery');
    await admin.put('/roles/delivery', { permissions: role.permissions.filter((p: string) => p !== 'cases.view') });
    const delivery = await login(USERS.delivery);
    expect((await delivery.get('/cases')).status).toBe(403);
    expect((await delivery.get('/cases')).body).toEqual({ message: 'Access restricted.' });
    await admin.put('/roles/delivery', { permissions: role.permissions });
  });
});
