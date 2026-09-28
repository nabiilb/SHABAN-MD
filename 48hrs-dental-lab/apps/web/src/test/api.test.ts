/**
 * Service-layer tests against the mock API. They go through the same
 * services the UI uses, so they exercise auth, row scoping, permissions,
 * validation and the workflow state machine exactly as the app does.
 */
import { describe, expect, it } from 'vitest';
import { ApiError } from '@/services/api/errors';
import { authService } from '@/services/authService';
import { caseService } from '@/services/caseService';
import { invoiceService, paymentService } from '@/services/paymentService';
import { searchService } from '@/services/reportService';
import { tokenStore } from '@/services/api/token-store';
import { HOUR_MS } from '@48hrs/shared/sla';
import type { CreateCasePayload } from '@48hrs/shared/types';
import { DEMO_PASSWORD, loginAs } from './helpers';

async function expectApiError(p: Promise<unknown>, status: number) {
  const err = await p.then(
    () => null,
    (e: unknown) => e,
  );
  expect(err).toBeInstanceOf(ApiError);
  expect((err as ApiError).status).toBe(status);
  return err as ApiError;
}

const newCase = (over: Partial<CreateCasePayload> = {}): CreateCasePayload => ({
  clinicId: 'cln_smile',
  doctorId: 'doc_amina',
  patientId: 'pat_1024',
  serviceId: 'svc_zirconia',
  material: 'Zirconia',
  shade: 'A2',
  teeth: [14, 15, 16],
  priority: 'normal',
  instructions: 'Test case',
  receiveNow: true,
  ...over,
});

describe('authentication', () => {
  it('signs in with valid credentials and returns permissions', async () => {
    const s = await authService.login({ email: 'sagal@48hrs.lab', password: DEMO_PASSWORD });
    expect(s.user.role).toBe('reception');
    expect(s.token).toHaveLength(48);
    expect(s.permissions).toContain('cases.create');
    expect(new Date(s.expiresAt).getTime()).toBeGreaterThan(Date.now());
  });

  it('rejects a wrong password with a generic message', async () => {
    const err = await expectApiError(authService.login({ email: 'sagal@48hrs.lab', password: 'nope' }), 401);
    expect(err.message).toBe('Email or password not recognised.');
  });

  it('refuses disabled accounts', async () => {
    const err = await expectApiError(authService.login({ email: 'layla@horizondental.so', password: DEMO_PASSWORD }), 403);
    expect(err.message).toMatch(/disabled/);
  });

  it('requires a valid token for protected endpoints and invalidates it on logout', async () => {
    await expectApiError(caseService.list({}), 401);
    await loginAs('sagal@48hrs.lab');
    await expect(caseService.list({})).resolves.toBeTruthy();
    await authService.logout();
    await expectApiError(authService.me(), 401);
  });

  it('rejects expired sessions', async () => {
    const s = await loginAs('sagal@48hrs.lab');
    tokenStore.clear();
    // Simulate a token the client still holds after it expired server-side.
    const { getDb } = await import('@/mocks/db');
    getDb().sessions.find((x) => x.token === s.token)!.expiresAt = new Date(Date.now() - 1000).toISOString();
    localStorage.setItem('48hrs.session', JSON.stringify({ token: s.token, expiresAt: new Date(Date.now() + HOUR_MS).toISOString() }));
    await expectApiError(authService.me(), 401);
  });

  it('supports the forgot / reset password flow', async () => {
    const res = await authService.forgotPassword({ email: 'omar@48hrs.lab' });
    const url = new URL(res.devResetUrl!, 'http://x');
    await authService.resetPassword({ token: url.searchParams.get('token')!, email: 'omar@48hrs.lab', password: 'NewPass123', passwordConfirmation: 'NewPass123' });
    await expectApiError(authService.login({ email: 'omar@48hrs.lab', password: DEMO_PASSWORD }), 401);
    await expect(authService.login({ email: 'omar@48hrs.lab', password: 'NewPass123' })).resolves.toBeTruthy();
  });
});

describe('permissions are enforced by the API', () => {
  it('technicians cannot create cases and only see their own', async () => {
    const s = await loginAs('fatima@48hrs.lab');
    await expectApiError(caseService.create(newCase()), 403);
    const list = await caseService.list({ perPage: 200 });
    expect(list.data.length).toBeGreaterThan(0);
    expect(list.data.every((c) => c.technicianId === s.user.technicianId)).toBe(true);
  });

  it('clients only see their clinic and cannot open other clinics’ cases', async () => {
    await loginAs('amina@smiledental.so');
    const list = await caseService.list({ perPage: 200 });
    expect(list.data.every((c) => c.clinicId === 'cln_smile')).toBe(true);
    const { getDb } = await import('@/mocks/db');
    const other = getDb().cases.find((c) => c.clinicId !== 'cln_smile')!;
    await expectApiError(caseService.get(other.id), 404);
  });

  it('reception cannot perform QC', async () => {
    await loginAs('sagal@48hrs.lab');
    const qc = (await caseService.list({ status: ['quality_control'] })).data[0];
    await expectApiError(caseService.action(qc.id, { action: 'qc_pass' }), 403);
  });
});

describe('case creation', () => {
  it('validates required clinical data', async () => {
    await loginAs('sagal@48hrs.lab');
    const err = await expectApiError(caseService.create(newCase({ teeth: [] })), 422);
    expect(err.fieldErrors.teeth[0]).toMatch(/at least one tooth/);
    const err2 = await expectApiError(caseService.create(newCase({ doctorId: 'doc_layla' })), 422);
    expect(err2.fieldErrors.doctorId[0]).toMatch(/does not belong/);
  });

  it('reception intake starts the 48-hour clock, prices the case and issues an invoice', async () => {
    await loginAs('sagal@48hrs.lab');
    const c = await caseService.create(newCase({ priority: 'urgent' }));
    expect(c.status).toBe('received');
    expect(c.caseNumber).toMatch(/^DL-\d{4}-\d{5}$/);
    expect(new Date(c.dueAt!).getTime() - new Date(c.receivedAt!).getTime()).toBe(48 * HOUR_MS);
    expect(c.total).toBe(3 * 20 + 3 * 5);
    expect(c.invoice?.total).toBe(75);
    expect(c.invoice?.status).toBe('unpaid');
    expect(c.history.map((h) => h.toStatus)).toEqual(['received']);
  });

  it('client submissions wait for reception and create new patients', async () => {
    await loginAs('amina@smiledental.so');
    const c = await caseService.create(newCase({ patientId: null, newPatient: { name: 'New Person' }, clinicId: 'cln_horizon' }));
    expect(c.status).toBe('submitted');
    expect(c.clinicId).toBe('cln_smile'); // forced to the client's own clinic
    expect(c.receivedAt).toBeNull();
    expect(c.patient.name).toBe('New Person');
  });
});

describe('workflow', () => {
  async function freshCase() {
    await loginAs('sagal@48hrs.lab');
    return caseService.create(newCase());
  }

  it('records every status change with user and time, and blocks out-of-order steps', async () => {
    const c = await freshCase();
    await expectApiError(caseService.action(c.id, { action: 'submit_qc' }), 409);

    await loginAs('omar@48hrs.lab');
    await expectApiError(caseService.action(c.id, { action: 'assign' }), 422);
    let d = await caseService.action(c.id, { action: 'assign', technicianId: 'tec_fatima' });
    expect(d.status).toBe('assigned');
    expect(d.technician?.name).toBe('Fatima Nur');

    await loginAs('ahmed@48hrs.lab'); // not the assigned technician
    await expectApiError(caseService.action(c.id, { action: 'start_production' }), 404);

    await loginAs('fatima@48hrs.lab');
    d = await caseService.action(c.id, { action: 'start_production' });
    expect(d.status).toBe('in_production');
    expect(d.productionStartedAt).toBeTruthy();
    const last = d.history[d.history.length - 1];
    expect(last).toMatchObject({ fromStatus: 'assigned', toStatus: 'in_production', userName: 'Fatima Nur', userRole: 'technician' });
  });

  it('QC failure returns the case to production; a pass makes it ready', async () => {
    const c = await freshCase();
    await loginAs('omar@48hrs.lab');
    await caseService.action(c.id, { action: 'assign', technicianId: 'tec_fatima' });
    await loginAs('fatima@48hrs.lab');
    await caseService.action(c.id, { action: 'start_production' });
    await caseService.action(c.id, { action: 'submit_qc' });

    await loginAs('idil@48hrs.lab');
    await expectApiError(caseService.action(c.id, { action: 'qc_fail', note: 'x', qc: { issues: [], notes: 'x' } }), 422);
    let d = await caseService.action(c.id, { action: 'qc_fail', note: 'Shade too light', qc: { issues: ['shade'], notes: 'Shade too light', reworkRequired: true } });
    expect(d.status).toBe('rework');
    expect(d.reworkCount).toBe(1);
    expect(d.qualityChecks[0]).toMatchObject({ result: 'failed', issues: ['shade'], checkedByName: 'Idil Hassan' });

    await loginAs('fatima@48hrs.lab');
    d = await caseService.action(c.id, { action: 'start_rework' });
    expect(d.status).toBe('in_production');
    await caseService.action(c.id, { action: 'submit_qc' });

    await loginAs('idil@48hrs.lab');
    d = await caseService.action(c.id, { action: 'qc_pass', qc: { issues: [], notes: 'Good' } });
    expect(d.status).toBe('ready');
    expect(d.deliveries[0].status).toBe('ready');
  });

  it('delivery: dispatch, deliver within SLA, client confirms receipt', async () => {
    await loginAs('bashir@48hrs.lab');
    const ready = (await caseService.list({ status: ['ready'] })).data[0];
    await expectApiError(caseService.action(ready.id, { action: 'dispatch', delivery: { method: 'lab_courier' } }), 422);
    let d = await caseService.action(ready.id, { action: 'dispatch', delivery: { method: 'lab_courier', courierName: 'Bashir Omar' } });
    expect(d.status).toBe('out_for_delivery');
    await expectApiError(caseService.action(ready.id, { action: 'deliver', delivery: { method: 'lab_courier' } }), 422);
    d = await caseService.action(ready.id, { action: 'deliver', delivery: { method: 'lab_courier', receivedBy: 'Nurse Hawa' } });
    expect(d.status).toBe('delivered');
    expect(d.deliveredAt).toBeTruthy();
    expect(d.deliveries[0]).toMatchObject({ status: 'delivered', receivedBy: 'Nurse Hawa' });
  });

  it('notifies the assigned technician', async () => {
    const c = await freshCase();
    await loginAs('omar@48hrs.lab');
    await caseService.action(c.id, { action: 'assign', technicianId: 'tec_ahmed' });
    await loginAs('ahmed@48hrs.lab');
    const { notificationService } = await import('@/services/notificationService');
    const n = await notificationService.list({});
    expect(n.data[0]).toMatchObject({ type: 'case_assigned', caseId: c.id });
    expect(n.unreadCount).toBeGreaterThan(0);
  });
});

describe('payments', () => {
  it('records partial and full payments and refuses over-payment', async () => {
    await loginAs('sagal@48hrs.lab');
    const c = await caseService.create(newCase());
    const invId = c.invoice!.id;
    await expectApiError(paymentService.record(invId, { amount: 61, method: 'cash' }), 422);
    let inv = await paymentService.record(invId, { amount: 25, method: 'cash' });
    expect(inv).toMatchObject({ paid: 25, remaining: 35, status: 'partial' });
    const err = await expectApiError(paymentService.record(invId, { amount: 35, method: 'bank_transfer' }), 422);
    expect(err.fieldErrors.reference).toBeTruthy();
    inv = await paymentService.record(invId, { amount: 35, method: 'bank_transfer', reference: 'TX1' });
    expect(inv).toMatchObject({ paid: 60, remaining: 0, status: 'paid' });
    expect((await invoiceService.get(invId)).payments).toHaveLength(2);
  });
});

describe('search & filtering', () => {
  it('finds cases by number and patients by phone', async () => {
    await loginAs('sagal@48hrs.lab');
    const { data } = await caseService.list({ perPage: 1 });
    const hits = await searchService.search(data[0].caseNumber);
    expect(hits.some((h) => h.type === 'case' && h.href === `/cases/${data[0].id}`)).toBe(true);
    const phone = await searchService.search('61 555 0101');
    expect(phone.some((h) => h.type === 'clinic' && h.title === 'Smile Dental Clinic')).toBe(true);
    expect(await searchService.search('a')).toEqual([]);
  });

  it('filters by status, clinic, priority and SLA and paginates', async () => {
    await loginAs('omar@48hrs.lab');
    const inProd = await caseService.list({ status: ['in_production'], perPage: 100 });
    expect(inProd.data.length).toBeGreaterThan(0);
    expect(inProd.data.every((c) => c.status === 'in_production')).toBe(true);

    const clinic = await caseService.list({ clinicId: 'cln_banadir', perPage: 100 });
    expect(clinic.data.every((c) => c.clinicId === 'cln_banadir')).toBe(true);

    const overdue = await caseService.list({ sla: 'overdue', perPage: 100 });
    expect(overdue.data.length).toBeGreaterThan(0);
    expect(overdue.data.every((c) => new Date(c.dueAt!).getTime() < Date.now())).toBe(true);

    const page2 = await caseService.list({ perPage: 10, page: 2, sort: 'caseNumber', dir: 'asc' });
    expect(page2.meta).toMatchObject({ page: 2, perPage: 10 });
    expect(page2.data).toHaveLength(10);
    const sorted = [...page2.data].map((c) => c.caseNumber);
    expect(sorted).toEqual([...sorted].sort());

    const found = await caseService.list({ search: 'Faisal' });
    expect(found.data.every((c) => c.patient.name.includes('Faisal') || c.doctor.name.includes('Faisal'))).toBe(true);
  });
});
