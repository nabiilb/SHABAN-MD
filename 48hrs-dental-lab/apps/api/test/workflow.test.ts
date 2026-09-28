import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CASE_ACTIONS, canPerformAction, type Actor } from '@48hrs/shared/workflow';
import { toCaseRequest } from '@48hrs/shared/case-requests';
import type { CaseActionKey } from '@48hrs/shared/types';
import { createReceivedCase, login, prisma, resetDb, USERS, type Client } from './helpers.ts';

const HOUR = 3_600_000;

beforeEach(() => resetDb());
afterEach(() => vi.useRealTimers());

async function step(c: Client, id: string, endpoint: string, body: object, expected = 200) {
  const res = await c.post(`/cases/${id}/${endpoint}`, body);
  expect(res.status, `${endpoint} ${JSON.stringify(body)} → ${JSON.stringify(res.body)}`).toBe(expected);
  return res.body;
}

describe('the 48-hour clock is set by the server', () => {
  it('received_at = server time and due_at = received_at + 48 h, ignoring any client clock', async () => {
    const reception = await login(USERS.reception);
    const before = Date.now();
    const c = await createReceivedCase(reception, { receivedAt: '2001-01-01T00:00:00Z' });
    const received = new Date(c.receivedAt).getTime();
    expect(received).toBeGreaterThanOrEqual(before - 1000);
    expect(received).toBeLessThanOrEqual(Date.now() + 1000);
    expect(new Date(c.dueAt).getTime() - received).toBe(48 * HOUR);
    expect(c.status).toBe('received');
    expect(c.invoice).toBeTruthy();
  });

  it('a portal submission has no clock until reception accepts it', async () => {
    const client = await login(USERS.client);
    const res = await client.post('/cases', { patientId: 'pat_1024', doctorId: 'doc_amina', clinicId: 'cln_banadir', serviceId: 'svc_emax', shade: 'A1', teeth: [8], priority: 'normal', instructions: '' });
    expect(res.status).toBe(201);
    expect(res.body).toMatchObject({ status: 'submitted', clinicId: 'cln_smile', receivedAt: null, dueAt: null, invoice: null });
    const reception = await login(USERS.reception);
    const accepted = await step(reception, res.body.id, 'status', { status: 'received' });
    expect(new Date(accepted.dueAt).getTime() - new Date(accepted.receivedAt).getTime()).toBe(48 * HOUR);
    expect(accepted.invoice).toBeTruthy();
  });
});

describe('complete production flow', () => {
  it('received → review → assigned → production → QC fail → rework → QC pass → ready → payment → out for delivery → delivered → completed', async () => {
    const reception = await login(USERS.reception);
    const manager = await login(USERS.manager);
    const tech = await login(USERS.technician);
    const qc = await login(USERS.qc);
    const delivery = await login(USERS.delivery);
    const client = await login(USERS.client);

    const c = await createReceivedCase(reception);
    await step(manager, c.id, 'status', { status: 'review' });
    const assigned = await step(manager, c.id, 'assign', { technicianId: 'tec_fatima', note: 'Priority client' });
    expect(assigned.technician.id).toBe('tec_fatima');
    await step(tech, c.id, 'status', { status: 'in_production' });
    await step(tech, c.id, 'status', { status: 'quality_control', note: 'Glazed' });

    const failed = await step(qc, c.id, 'qc', { result: 'fail', issues: ['shade', 'contacts'], notes: 'Shade too light' });
    expect(failed).toMatchObject({ status: 'rework', reworkCount: 1 });
    expect(failed.qualityChecks.at(-1)).toMatchObject({ result: 'failed', reworkRequired: true, issues: ['contacts', 'shade'], notes: 'Shade too light', checkedByName: 'Idil Hassan' });
    await step(tech, c.id, 'rework', {});
    await step(tech, c.id, 'status', { status: 'quality_control' });
    const passed = await step(qc, c.id, 'qc', { result: 'pass', issues: [], notes: 'Fit verified' });
    expect(passed.status).toBe('ready');
    expect(passed.deliveries.at(-1)).toMatchObject({ status: 'ready' });

    expect(passed.invoice).toBeNull(); // QC has no invoices.view: money is not in its response
    const invoice = (await reception.get(`/cases/${c.id}`)).body.invoice;
    const pay = await reception.post('/payments', { invoiceId: invoice.id, amount: passed.total, method: 'cash' });
    expect(pay.status).toBe(201);
    expect(pay.body).toMatchObject({ status: 'paid', remaining: 0, paid: passed.total });

    const out = await step(delivery, c.id, 'delivery', { status: 'out_for_delivery', method: 'lab_courier', courierName: 'Bashir Omar' });
    expect(out.deliveries.at(-1)).toMatchObject({ status: 'out_for_delivery', courierName: 'Bashir Omar' });
    const delivered = await step(delivery, c.id, 'delivery', { status: 'delivered', method: 'lab_courier', receivedBy: 'Nurse Hawa', deliveredTo: 'Smile front desk', notes: 'Left with nurse' });
    expect(delivered.deliveries.at(-1)).toMatchObject({ status: 'delivered', receivedBy: 'Nurse Hawa', deliveredTo: 'Smile front desk', notes: 'Left with nurse', recordedByName: 'Bashir Omar' });
    expect(delivered.deliveries.at(-1).deliveredAt).toBeTruthy();
    expect(delivered.deliveredAt).toBeTruthy();

    const done = await step(client, c.id, 'status', { status: 'completed' });
    expect(done.status).toBe('completed');

    // Persisted history, in order, with actors and notes.
    const history = await prisma.caseStatusHistory.findMany({ where: { caseId: c.id }, orderBy: { createdAt: 'asc' } });
    expect(history.map((h) => h.toStatus)).toEqual(['received', 'review', 'assigned', 'in_production', 'quality_control', 'rework', 'in_production', 'quality_control', 'ready', 'out_for_delivery', 'delivered', 'completed']);
    expect(history[2].note).toBe('Assigned to Fatima Nur — Priority client');
    expect(history[5].note).toBe('QC failed — Shade too light');
    expect(history[10].note).toBe('Left with nurse'); // the user's delivery note is the history note
    expect(await prisma.caseAssignment.count({ where: { caseId: c.id } })).toBe(1);
    expect(await prisma.qualityIssue.count({ where: { qualityCheck: { caseId: c.id } } })).toBe(2);
    expect(await prisma.activityLog.count({ where: { subjectId: c.id } })).toBeGreaterThanOrEqual(12);
  });

  it('reassignment closes the previous assignment', async () => {
    const manager = await login(USERS.manager);
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception);
    await step(manager, c.id, 'assign', { technicianId: 'tec_fatima' });
    const again = await step(manager, c.id, 'assign', { technicianId: 'tec_ahmed' });
    expect(again.technician.id).toBe('tec_ahmed');
    const log = await prisma.caseAssignment.findMany({ where: { caseId: c.id }, orderBy: { assignedAt: 'asc' } });
    expect(log.map((a) => [a.technicianId, !!a.unassignedAt])).toEqual([['tec_fatima', true], ['tec_ahmed', false]]);
    expect(again.history.at(-1).note).toBe('Reassigned to Ahmed Hassan');
  });
});

describe('invalid requests', () => {
  it('invalid workflow transitions are 409 with the standard message', async () => {
    const reception = await login(USERS.reception);
    const qc = await login(USERS.qc);
    const c = await createReceivedCase(reception);
    const res = await qc.post(`/cases/${c.id}/qc`, { result: 'pass', issues: [], notes: '' });
    expect(res.status).toBe(409);
    expect(res.body).toMatchObject({ message: 'Invalid workflow transition.', currentStatus: 'received' });
    const jump = await reception.post(`/cases/${c.id}/status`, { status: 'delivered' });
    expect(jump.status).toBe(409);
    const viaWrongEndpoint = await reception.post(`/cases/${c.id}/status`, { status: 'assigned' });
    expect(viaWrongEndpoint.status).toBe(409);
  });

  it('two people moving the same case at once: one wins, the other gets 409', async () => {
    const reception = await login(USERS.reception);
    const manager = await login(USERS.manager);
    const admin = await login(USERS.admin);
    const c = await createReceivedCase(reception);
    const [a, b] = await Promise.all([manager.post(`/cases/${c.id}/status`, { status: 'review' }), admin.post(`/cases/${c.id}/status`, { status: 'review' })]);
    expect([a.status, b.status].sort()).toEqual([200, 409]);
    expect(await prisma.caseStatusHistory.count({ where: { caseId: c.id, toStatus: 'review' } })).toBe(1);
  });

  it('step input is validated (422) with the request field names', async () => {
    const reception = await login(USERS.reception);
    const manager = await login(USERS.manager);
    const qc = await login(USERS.qc);
    const tech = await login(USERS.technician);
    const delivery = await login(USERS.delivery);
    const c = await createReceivedCase(reception);
    expect((await manager.post(`/cases/${c.id}/assign`, {})).body.errors).toHaveProperty('technicianId');
    expect((await manager.post(`/cases/${c.id}/assign`, { technicianId: 'tec_nobody' })).status).toBe(422);
    await step(manager, c.id, 'assign', { technicianId: 'tec_fatima' });
    await step(tech, c.id, 'status', { status: 'in_production' });
    await step(tech, c.id, 'status', { status: 'quality_control' });
    const qcFail = await qc.post(`/cases/${c.id}/qc`, { result: 'fail', issues: [], notes: '' });
    expect(qcFail.status).toBe(422);
    expect(Object.keys(qcFail.body.errors).sort()).toEqual(['issues', 'notes']);
    expect((await qc.post(`/cases/${c.id}/qc`, { result: 'maybe' })).body.errors).toHaveProperty('result');
    await step(qc, c.id, 'qc', { result: 'pass', issues: [], notes: '' });
    const noCourier = await delivery.post(`/cases/${c.id}/delivery`, { status: 'out_for_delivery', method: 'lab_courier' });
    expect(noCourier.status).toBe(422);
    expect(noCourier.body.errors).toHaveProperty('courierName');
    const noReceiver = await delivery.post(`/cases/${c.id}/delivery`, { status: 'delivered', method: 'clinic_pickup' });
    expect(noReceiver.body.errors).toHaveProperty('receivedBy');
    const cancel = await reception.post(`/cases/${c.id}/status`, { status: 'cancelled' });
    expect(cancel.status).toBe(403); // reception cannot cancel
  });

  it('a rejected acceptance changes nothing (no clock, no invoice, no payment)', async () => {
    const client = await login(USERS.client);
    const reception = await login(USERS.reception);
    const sub = await client.post('/cases', { patientId: 'pat_1024', doctorId: 'doc_amina', serviceId: 'svc_zirconia', shade: 'A2', teeth: [3], priority: 'normal', instructions: '' });
    const res = await reception.post(`/cases/${sub.body.id}/status`, { status: 'received', payment: { amount: 9999, method: 'bank_transfer' } });
    expect(res.status).toBe(422);
    expect(Object.keys(res.body.errors).sort()).toEqual(['payment.amount', 'payment.reference']);
    const row = await prisma.dentalCase.findUniqueOrThrow({ where: { id: sub.body.id }, include: { invoice: true } });
    expect(row).toMatchObject({ status: 'submitted', receivedAt: null, dueAt: null, invoice: null });
    const ok = await step(reception, sub.body.id, 'status', { status: 'received', payment: { amount: 5, method: 'cash' } });
    expect(ok.invoice).toMatchObject({ paid: 5, status: 'partial' });
  });
});

describe('permissions on every step (server-side, independent of the UI)', () => {
  const roles = { reception: USERS.reception, manager: USERS.manager, technician: USERS.technician, technician2: USERS.technician2, qc: USERS.qc, delivery: USERS.delivery, client: USERS.client, admin: USERS.admin };

  it.each(Object.keys(CASE_ACTIONS) as CaseActionKey[])('%s: allowed exactly for the roles the shared rules allow', async (action) => {
    const def = CASE_ACTIONS[action];
    const from = def.from[0];
    // A seeded case already in the "from" status; client-portal steps need it to belong to the client's clinic.
    let target = await prisma.dentalCase.findFirstOrThrow({ where: { status: from }, orderBy: { createdAt: 'desc' } });
    if (action === 'resubmit' || action === 'confirm_receipt') {
      target = await prisma.dentalCase.update({ where: { id: target.id }, data: { clinicId: 'cln_smile', doctorId: 'doc_amina' } });
    }
    for (const [name, email] of Object.entries(roles)) {
      const c = await login(email);
      const me = (await c.get('/auth/me')).body;
      const actor: Actor = { user: me.user, permissions: me.permissions };
      const expected = canPerformAction(action, target, actor);
      const { endpoint, body } = toCaseRequest({ action, note: 'x', technicianId: 'tec_ali', qc: { issues: [], notes: '' }, delivery: { method: 'clinic_pickup' } });
      // Probe with an input that fails validation: a permitted role gets 422, a forbidden one 403 (or 404 outside its scope).
      const probe = action === 'assign' ? { ...body, technicianId: '' } : action === 'qc_fail' || action === 'qc_pass' ? { ...body, result: action === 'qc_pass' ? 'pass' : 'fail', issues: [], notes: '' } : action === 'deliver' || action === 'dispatch' ? { ...body, courierName: '', receivedBy: '', method: 'lab_courier' } : { ...body, note: '' };
      const res = await c.post(`/cases/${target.id}/${endpoint}`, probe);
      if (expected) expect([200, 422], `${name} ${action}: ${res.status} ${JSON.stringify(res.body)}`).toContain(res.status);
      else expect([403, 404], `${name} ${action}: ${res.status}`).toContain(res.status);
      if (res.status === 200) await resetDb();
    }
  });

  it('a technician cannot move another technician\'s case, and cannot even see it (404)', async () => {
    const other = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production', technicianId: { not: 'tec_fatima' } } });
    const tech = await login(USERS.technician);
    expect((await tech.get(`/cases/${other.id}`)).status).toBe(404);
    expect((await tech.post(`/cases/${other.id}/status`, { status: 'quality_control' })).status).toBe(404);
  });
});

describe('SLA states from stored timestamps at server time', () => {
  it('on track → at risk → overdue, listed and counted by the API; delivered late is recorded as late', async () => {
    const start = Date.now();
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception);
    const manager = await login(USERS.manager);

    const listed = async (who: Client, sla: string) => ((await who.get(`/cases?sla=${sla}&perPage=200`)).body.data as { id: string }[]).some((x) => x.id === c.id);
    expect(await listed(manager, 'on_track')).toBe(true);

    vi.useFakeTimers({ toFake: ['Date'], now: start + 40 * HOUR });
    const m1 = await login(USERS.manager);
    expect(await listed(m1, 'at_risk')).toBe(true);
    expect(await listed(m1, 'overdue')).toBe(false);

    vi.setSystemTime(start + 49 * HOUR);
    const m2 = await login(USERS.manager);
    expect(await listed(m2, 'overdue')).toBe(true);
    const counts = (await m2.get('/cases/counts')).body;
    const overdueNow = await prisma.dentalCase.count({ where: { status: { in: ['received', 'review', 'assigned', 'in_production', 'rework', 'quality_control', 'ready', 'out_for_delivery'] }, dueAt: { lte: new Date() } } });
    expect(counts.overdue).toBe(overdueNow);
    expect((await m2.get('/dashboard?period=today')).body.overdue).toBe(overdueNow);

    // Deliver it now, late.
    await step(m2, c.id, 'assign', { technicianId: 'tec_fatima' });
    const tech = await login(USERS.technician);
    await step(tech, c.id, 'status', { status: 'in_production' });
    await step(tech, c.id, 'status', { status: 'quality_control' });
    await step(await login(USERS.qc), c.id, 'qc', { result: 'pass', issues: [], notes: '' });
    const late = await step(await login(USERS.delivery), c.id, 'delivery', { status: 'delivered', method: 'clinic_pickup', receivedBy: 'Front desk' });
    expect(late.history.at(-1).note).toMatch(/outside the 48-hour window/);
  });
});
