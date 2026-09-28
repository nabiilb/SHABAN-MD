import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { runDeadlineScan } from '../src/jobs/deadline-scan.ts';
import { createReceivedCase, login, prisma, resetDb, USERS } from './helpers.ts';

const HOUR = 3_600_000;
const labToday = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Mogadishu' }).format(new Date());
const daysAgo = (n: number) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Mogadishu' }).format(new Date(Date.now() - n * 24 * HOUR));

afterEach(() => vi.useRealTimers());

describe('directory', () => {
  beforeEach(() => resetDb());

  it('patients: search, filter, sort, paginate, CRUD and delete guard', async () => {
    const reception = await login(USERS.reception);
    expect((await reception.get('/patients?search=PT-1024')).body.data.map((p: { code: string }) => p.code)).toEqual(['PT-1024']);
    const byPhone = await reception.get('/patients?search=61 9');
    expect(byPhone.body.data.length).toBeGreaterThan(0);
    const clinic = (await reception.get('/patients?clinicId=cln_aurora&perPage=100')).body.data as { clinicId: string }[];
    expect(clinic.every((p) => p.clinicId === 'cln_aurora')).toBe(true);
    const byCases = (await reception.get('/patients?sort=caseCount&dir=desc&perPage=100')).body.data as { caseCount: number }[];
    expect(byCases.map((p) => p.caseCount)).toEqual([...byCases.map((p) => p.caseCount)].sort((a, b) => b - a));
    const byLast = (await reception.get('/patients?sort=lastCaseAt&dir=desc&perPage=100')).body.data as { lastCaseAt: string | null }[];
    const lasts = byLast.map((p) => p.lastCaseAt).filter(Boolean) as string[];
    expect(lasts).toEqual([...lasts].sort().reverse());
    const page2 = (await reception.get('/patients?perPage=10&page=2')).body.meta;
    expect(page2).toMatchObject({ page: 2, perPage: 10, total: await prisma.patient.count() });

    const created = await reception.post('/patients', { name: 'New Person', phone: '+252 61 222 3333', email: 'np@mail.so', gender: 'female', dateOfBirth: '1990-04-02', clinicId: 'cln_smile' });
    expect(created.status).toBe(201);
    expect(created.body.code).toMatch(/^PT-/);
    expect(created.body.dateOfBirth).toBe('1990-04-02');
    expect((await reception.post('/patients', { name: 'Dup', code: 'pt-1024' })).body.errors.code).toBeTruthy();
    expect((await reception.post('/patients', { name: 'Future', dateOfBirth: '2999-01-01' })).body.errors.dateOfBirth).toBeTruthy();
    expect((await reception.put(`/patients/${created.body.id}`, { name: 'Renamed', phone: 'bad' })).body.errors.phone).toBeTruthy();
    expect((await reception.put(`/patients/${created.body.id}`, { name: 'Renamed' })).body.name).toBe('Renamed');
    expect((await reception.del(`/patients/${created.body.id}`)).status).toBe(403); // reception lacks patients.delete
    const admin = await login(USERS.admin);
    expect((await admin.del('/patients/pat_1024')).status).toBe(422);
    expect((await admin.del(`/patients/${created.body.id}`)).status).toBe(204);
    const detail = (await admin.get('/patients/pat_1024')).body;
    expect(detail.stats.totalCases).toBe(detail.caseCount);
  });

  it('doctors and clinics: filters, stats, uniqueness and delete guards', async () => {
    const admin = await login(USERS.admin);
    expect((await admin.get('/doctors?status=inactive')).body.data.map((d: { name: string }) => d.name)).toEqual(['Dr. Nasra Ahmed']);
    const smile = (await admin.get('/doctors?clinicId=cln_smile')).body.data as { clinicName: string }[];
    expect(smile.every((d) => d.clinicName === 'Smile Dental Clinic')).toBe(true);
    const byCount = (await admin.get('/clinics?sort=caseCount&dir=desc')).body.data as { caseCount: number; outstanding: number }[];
    expect(byCount.map((k) => k.caseCount)).toEqual([...byCount.map((k) => k.caseCount)].sort((a, b) => b - a));
    expect((await admin.get('/clinics?search=aurora')).body.data.map((k: { name: string }) => k.name)).toEqual(['Aurora Dental Studio']);
    expect((await admin.post('/clinics', { name: 'smile dental clinic', phone: '+252 61 000 0000' })).body.errors.name).toBeTruthy();
    expect((await admin.del('/doctors/doc_amina')).status).toBe(422);
    expect((await admin.del('/clinics/cln_smile')).status).toBe(422);
    const k = await admin.post('/clinics', { name: 'Temp Clinic', phone: '+252 61 000 1111' });
    expect(k.status).toBe(201);
    const d = await admin.post('/doctors', { name: 'Dr. Temp', clinicId: k.body.id, phone: '+252 61 000 2222' });
    expect(d.status).toBe(201);
    expect((await admin.del(`/clinics/${k.body.id}`)).status).toBe(422); // has a doctor
    expect((await admin.del(`/doctors/${d.body.id}`)).status).toBe(204);
    expect((await admin.del(`/clinics/${k.body.id}`)).status).toBe(204);
    const clinicDetail = (await admin.get('/clinics/cln_smile')).body;
    expect(clinicDetail.doctors.length).toBe(clinicDetail.doctorCount);
    const reception = await login(USERS.reception);
    expect((await reception.del('/doctors/doc_yasin')).status).toBe(403);
    const client = await login(USERS.client);
    expect((await client.get('/clinics')).body.data.map((c: { id: string }) => c.id)).toEqual(['cln_smile']);
  });

  it('technicians: workload, own profile, deactivation and delete guards', async () => {
    const manager = await login(USERS.manager);
    const active = (await manager.get('/technicians?active=true&sort=activeCases&dir=desc')).body.data as { activeCases: number }[];
    expect(active.map((t) => t.activeCases)).toEqual([...active.map((t) => t.activeCases)].sort((a, b) => b - a));
    expect((await manager.get('/technicians?search=ceramics')).body.data.map((t: { name: string }) => t.name)).toEqual(['Ahmed Hassan']);
    const tech = await login(USERS.technician);
    const own = await tech.get('/technicians/tec_fatima');
    expect(own.status).toBe(200);
    expect(own.body.activeCaseList.every((c: { technicianId: string }) => c.technicianId === 'tec_fatima')).toBe(true);
    expect((await tech.get('/technicians/tec_ahmed')).status).toBe(403);
    const busy = await manager.put('/technicians/tec_fatima', { name: 'Fatima Nur', email: 'fatima@48hrs.lab', phone: '+252 61 800 2001', specialty: 'Crown & bridge', active: false });
    expect(busy.status).toBe(422);
    const t = await manager.post('/technicians', { name: 'New Tech', email: 'new.tech@48hrs.lab', phone: '+252 61 000 0000', specialty: 'Ceramics', active: true });
    expect(t.status).toBe(201);
    expect((await manager.del(`/technicians/${t.body.id}`)).status).toBe(403); // lab manager: create/edit, not delete
    const admin = await login(USERS.admin);
    expect((await admin.del('/technicians/tec_fatima')).status).toBe(422);
    expect((await admin.del(`/technicians/${t.body.id}`)).status).toBe(204);
  });
});

describe('quality control and delivery history', () => {
  beforeAll(() => resetDb());

  it('lists QC checks with results, filters and scope', async () => {
    const qc = await login(USERS.qc);
    const failed = (await qc.get('/quality-control?result=failed&perPage=200')).body.data as { result: string; issues: string[]; caseNumber: string }[];
    expect(failed.length).toBeGreaterThan(0);
    expect(failed.every((r) => r.result === 'failed' && r.issues.length > 0 && r.caseNumber)).toBe(true);
    const tech = await login(USERS.technician);
    expect((await tech.get('/quality-control')).status).toBe(403);
  });

  it('lists deliveries with who delivered, when, and who received', async () => {
    const delivery = await login(USERS.delivery);
    const done = (await delivery.get('/deliveries?status=delivered&perPage=200')).body.data as { status: string; receivedBy: string; recordedByName: string; deliveredAt: string }[];
    expect(done.length).toBeGreaterThan(0);
    expect(done.every((d) => d.status === 'delivered' && d.receivedBy && d.recordedByName && d.deliveredAt)).toBe(true);
  });
});

describe('notifications and the deadline worker', () => {
  beforeEach(() => resetDb());

  it('workflow events notify the right people; read/unread per user; others\' are 404', async () => {
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception);
    const manager = await login(USERS.manager);
    const list = (await manager.get('/notifications')).body;
    const note = list.data.find((n: { caseId: string }) => n.caseId === c.id);
    expect(note).toMatchObject({ type: 'case_received', caseNumber: c.caseNumber, readAt: null });
    await manager.post(`/cases/${c.id}/assign`, { technicianId: 'tec_fatima' });
    const tech = await login(USERS.technician);
    const techList = (await tech.get('/notifications?unreadOnly=true')).body;
    expect(techList.data[0]).toMatchObject({ type: 'case_assigned', caseId: c.id });
    expect(techList.data.every((n: { readAt: string | null }) => !n.readAt)).toBe(true);

    expect((await manager.post(`/notifications/${note.id}/read`)).status).toBe(204);
    const after = (await manager.get('/notifications')).body;
    expect(after.unreadCount).toBe(list.unreadCount - 1);
    expect((await tech.post(`/notifications/${note.id}/read`)).status).toBe(404);
    expect((await manager.post('/notifications/read-all')).status).toBe(204);
    expect((await manager.get('/notifications')).body.unreadCount).toBe(0);
    expect((await manager.get('/cases/counts')).body.unreadNotifications).toBe(0);
  });

  it('the worker raises at-risk and overdue alerts once per case, whatever the number of runs', async () => {
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception);
    await runDeadlineScan(); // settle the seeded board
    const count = (type: string) => prisma.notification.count({ where: { caseId: c.id, type: type as never } });

    const r1 = await runDeadlineScan(Date.now() + 40 * HOUR);
    expect(r1.atRisk).toBeGreaterThanOrEqual(1);
    const atRiskNotes = await count('deadline_approaching');
    expect(atRiskNotes).toBeGreaterThan(0);
    await runDeadlineScan(Date.now() + 41 * HOUR);
    expect(await count('deadline_approaching')).toBe(atRiskNotes);

    await Promise.all([runDeadlineScan(Date.now() + 49 * HOUR), runDeadlineScan(Date.now() + 49 * HOUR)]); // two workers at once
    const overdueNotes = await count('case_overdue');
    expect(overdueNotes).toBeGreaterThan(0);
    await runDeadlineScan(Date.now() + 60 * HOUR);
    expect(await count('case_overdue')).toBe(overdueNotes);
    const row = await prisma.dentalCase.findUniqueOrThrow({ where: { id: c.id } });
    expect(row.atRiskNotifiedAt && row.overdueNotifiedAt).toBeTruthy();
    const recipients = await prisma.notification.findMany({ where: { caseId: c.id, type: 'case_overdue' }, include: { user: true } });
    expect(new Set(recipients.map((n) => n.user.roleKey))).toEqual(new Set(['lab_manager', 'admin', 'reception']));
  });
});

describe('dashboard, reports and search', () => {
  beforeAll(() => resetDb());

  it('dashboard KPIs come from the database; money only with reports.financial', async () => {
    const admin = await login(USERS.admin);
    const d = (await admin.get('/dashboard?period=30d')).body;
    const inLab = ['received', 'review', 'assigned', 'in_production', 'rework', 'quality_control', 'ready', 'out_for_delivery'];
    expect(d.activeCases).toBe(await prisma.dentalCase.count({ where: { status: { in: inLab as never } } }));
    expect(d.pendingQc).toBe(await prisma.dentalCase.count({ where: { status: 'quality_control' } }));
    expect(d.completed).toBe(await prisma.dentalCase.count({ where: { status: { in: ['delivered', 'completed'] } } }));
    expect(d.overdue).toBe(await prisma.dentalCase.count({ where: { status: { in: inLab as never }, dueAt: { lte: new Date() } } }));
    expect(d.revenue).toBeGreaterThan(0);
    expect(d.revenueByMonth).toHaveLength(6);
    expect(d.last14Days).toHaveLength(14);
    const today = (await admin.get('/dashboard?period=today')).body;
    expect(today.newCases).toBeLessThanOrEqual(d.newCases);
    expect(today.periodStart).toBe(labToday());
    const manager = await login(USERS.manager);
    const m = (await manager.get('/dashboard')).body;
    expect([m.revenue, m.collected, m.outstanding, m.revenueByMonth]).toEqual([null, null, null, null]);
    const tech = await login(USERS.technician);
    expect((await tech.get('/dashboard')).body.activeCases).toBe(await prisma.dentalCase.count({ where: { technicianId: 'tec_fatima', status: { in: inLab as never } } }));
  });

  it('reports honour every filter; financial section needs reports.financial', async () => {
    const admin = await login(USERS.admin);
    const range = `from=${daysAgo(95)}&to=${labToday()}`;
    const all = (await admin.get(`/reports/cases?${range}`)).body;
    expect(all.totals.cases).toBeGreaterThan(40);
    expect(all.daily).toHaveLength(96);
    const byTech = (await admin.get(`/reports/technicians?${range}&technicianId=tec_fatima`)).body;
    expect(byTech.technicians.map((t: { name: string }) => t.name)).toEqual(['Fatima Nur']);
    const byClinic = (await admin.get(`/reports/clinics?${range}&clinicId=cln_banadir`)).body;
    expect(byClinic.clinics.map((k: { name: string }) => k.name)).toEqual(['Banadir Dental Centre']);
    const byStatus = (await admin.get(`/reports/cases?${range}&status=in_production`)).body;
    expect(byStatus.byStatus.map((s: { status: string }) => s.status)).toEqual(['in_production']);
    const byType = (await admin.get(`/reports/cases?${range}&caseType=denture`)).body;
    expect(byType.byCaseType.map((t: { caseType: string }) => t.caseType)).toEqual(['denture']);
    const byDoctor = (await admin.get(`/reports/cases?${range}&doctorId=doc_amina`)).body;
    expect(byDoctor.totals.cases).toBeLessThan(all.totals.cases);
    const production = (await admin.get(`/reports/production?${range}`)).body;
    expect(production.stages).toHaveLength(5);
    const money = (await admin.get(`/reports/financial?${range}`)).body;
    expect(money.totals.revenue).toBeGreaterThan(0);
    expect(money.totals.revenue).toBeCloseTo(money.totals.collected + money.totals.outstanding, 0);

    const manager = await login(USERS.manager);
    expect((await manager.get(`/reports/cases?${range}`)).status).toBe(200);
    expect((await manager.get(`/reports/financial?${range}`)).status).toBe(403);
    expect((await admin.get(`/reports/cases?from=${labToday()}&to=${daysAgo(3)}`)).status).toBe(422);
    expect((await admin.get('/reports/cases')).status).toBe(422);
    const reception = await login(USERS.reception);
    expect((await reception.get(`/reports/cases?${range}`)).status).toBe(403);
  });

  it('global search finds cases, patients, doctors, clinics, phones and invoices within scope', async () => {
    const admin = await login(USERS.superAdmin);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production' } });
    const inv = await prisma.invoice.findFirstOrThrow();
    const hit = async (q: string, type: string, href: RegExp) => {
      const res = (await admin.get(`/search?q=${encodeURIComponent(q)}`)).body as { type: string; href: string }[];
      expect(res.some((h) => h.type === type && href.test(h.href)), `${q} → ${type}`).toBe(true);
    };
    await hit(c.caseNumber, 'case', new RegExp(`^/cases/${c.id}$`));
    await hit('Hodan Jama', 'patient', /^\/patients\/pat_/);
    await hit('Hibo', 'doctor', /^\/doctors\/doc_hibo$/);
    await hit('Banadir', 'clinic', /^\/clinics\/cln_banadir$/);
    await hit('700 1004', 'doctor', /^\/doctors\/doc_abdirahman$/);
    await hit(inv.invoiceNumber, 'invoice', new RegExp(`^/invoices/${inv.id}$`));
    expect((await admin.get('/search?q=a')).body).toEqual([]);
    const client = await login(USERS.client);
    const other = await prisma.dentalCase.findFirstOrThrow({ where: { clinicId: { not: 'cln_smile' } } });
    expect((await client.get(`/search?q=${other.caseNumber}`)).body).toEqual([]);
  });
});

describe('administration', () => {
  beforeEach(() => resetDb());

  it('users: create with policy, role rules, self-protection and history guard', async () => {
    const admin = await login(USERS.admin);
    const weak = await admin.post('/users', { name: 'Weak', email: 'weak@48hrs.lab', role: 'reception', password: 'short' });
    expect(weak.body.errors.password).toBeTruthy();
    expect((await admin.post('/users', { name: 'Dup', email: USERS.reception, role: 'reception', password: 'GoodPass123' })).body.errors.email).toBeTruthy();
    expect((await admin.post('/users', { name: 'Boss', email: 'boss@48hrs.lab', role: 'super_admin', password: 'GoodPass123' })).body.errors.role).toBeTruthy();
    expect((await admin.post('/users', { name: 'Clinic', email: 'c@x.so', role: 'client', password: 'GoodPass123' })).body.errors.clinicId).toBeTruthy();

    const created = await admin.post('/users', { name: 'New Tech', email: 'newtech@48hrs.lab', role: 'technician', password: 'GoodPass123' });
    expect(created.status).toBe(201);
    expect(created.body.technicianId).toBeTruthy(); // a technician profile was created and linked
    expect((await login('newtech@48hrs.lab', 'GoodPass123').then((c) => c.get('/auth/me'))).body.user.role).toBe('technician');

    const self = (await admin.get('/auth/me')).body.user;
    expect((await admin.patch(`/users/${self.id}/status`, { active: false })).status).toBe(422);
    expect((await admin.put(`/users/${self.id}`, { ...self, role: 'reception' })).body.errors.role).toBeTruthy();
    expect((await admin.put('/users/usr_khalid', { name: 'Khalid Aden', email: 'khalid@48hrs.lab', role: 'super_admin', active: true })).status).toBe(403);
    expect((await admin.del('/users/usr_sagal')).status).toBe(422); // has case history
    expect((await admin.del(`/users/${created.body.id}`)).status).toBe(204);
    const reception = await login(USERS.reception);
    expect((await reception.get('/users')).status).toBe(403);
  });

  it('roles: locked Super Admin, unknown permissions ignored, catalogue from the database', async () => {
    const root = await login(USERS.superAdmin);
    expect((await root.put('/roles/super_admin', { permissions: [] })).status).toBe(422);
    const res = await root.put('/roles/qc', { permissions: ['qc.view', 'qc.perform', 'cases.view', 'made.up'] });
    expect(res.body.permissions.sort()).toEqual(['cases.view', 'qc.perform', 'qc.view']);
    const perms = (await root.get('/permissions')).body as { key: string }[];
    expect(perms.map((p) => p.key)).toEqual(expect.arrayContaining(['cases.update_status', 'doctors.delete', 'technicians.create', 'reports.financial', 'roles.manage', 'settings.manage']));
    const admin = await login(USERS.admin);
    expect((await admin.put('/roles/qc', { permissions: [] })).status).toBe(403); // admin lacks roles.manage
  });

  it('services, settings and the activity log', async () => {
    const admin = await login(USERS.admin);
    const svc = await admin.post('/services', { name: 'Inlay', caseType: 'crown', unitMode: 'tooth', unitPrice: 30, defaultMaterial: 'Composite', active: true });
    expect(svc.status).toBe(201);
    expect((await admin.post('/services', { name: 'X', caseType: 'crown', unitMode: 'tooth', unitPrice: -1, defaultMaterial: 'Y' })).body.errors.unitPrice).toBeTruthy();
    expect((await admin.del('/services/svc_zirconia')).status).toBe(422);
    expect((await admin.del(`/services/${svc.body.id}`)).status).toBe(204);

    const settings = (await admin.get('/settings')).body;
    expect((await admin.put('/settings', { ...settings, criticalHours: 20, atRiskHours: 12 })).body.errors.criticalHours).toBeTruthy();
    expect((await admin.put('/settings', { ...settings, currency: 'dollars' })).body.errors.currency).toBeTruthy();
    const saved = await admin.put('/settings', { ...settings, slaHours: 72 });
    expect(saved.body.slaHours).toBe(72);
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception);
    expect(new Date(c.dueAt).getTime() - new Date(c.receivedAt).getTime()).toBe(72 * HOUR);
    expect((await reception.put('/settings', settings)).status).toBe(403);

    const activity = (await admin.get('/activity?perPage=50')).body;
    expect(activity.data[0].createdAt >= activity.data.at(-1).createdAt).toBe(true);
    const caseFeed = (await admin.get(`/activity?subjectType=case&search=${c.caseNumber}`)).body.data as { description: string }[];
    expect(caseFeed.some((a) => a.description.startsWith('Created as received'))).toBe(true);
    expect((await reception.get('/activity')).status).toBe(403);
  });
});
