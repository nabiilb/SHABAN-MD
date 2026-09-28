/**
 * Audit suite: SLA against stored timestamps (with time travel), workflow
 * permission matrix, transition integrity, payment statuses, dashboard period
 * and the shared validation rules.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/services/api/errors';
import { caseService } from '@/services/caseService';
import { notificationService } from '@/services/notificationService';
import { paymentService, invoiceService } from '@/services/paymentService';
import { reportService } from '@/services/reportService';
import { technicianService } from '@/services/technicianService';
import { getSlaInfo, HOUR_MS, onTimeRate, deliveredOnTime } from '@48hrs/shared/sla';
import { CASE_ACTIONS, validateActionInput } from '@48hrs/shared/workflow';
import { DEFAULT_ROLES } from '@48hrs/shared/permissions';
import { getDb } from '@/mocks/db';
import type { CaseActionKey, CreateCasePayload } from '@48hrs/shared/types';
import { loginAs } from './helpers';

afterEach(() => vi.useRealTimers());

const DAY = 24 * HOUR_MS;
const base: CreateCasePayload = {
  clinicId: 'cln_smile', doctorId: 'doc_amina', patientId: 'pat_1024', serviceId: 'svc_zirconia',
  material: 'Zirconia', shade: 'A2', teeth: [14], priority: 'normal', instructions: '', receiveNow: true,
};

async function status(p: Promise<unknown>) {
  return p.then(() => 200, (e: unknown) => (e instanceof ApiError ? e.status : -1));
}

/** Move a received case to "ready" through the real workflow. */
async function toReady(id: string) {
  await loginAs('omar@48hrs.lab');
  await caseService.action(id, { action: 'assign', technicianId: 'tec_fatima' });
  await loginAs('fatima@48hrs.lab');
  await caseService.action(id, { action: 'start_production' });
  await caseService.action(id, { action: 'submit_qc' });
  await loginAs('idil@48hrs.lab');
  await caseService.action(id, { action: 'qc_pass', qc: { issues: [], notes: 'OK' } });
}

describe('48-hour SLA — domain boundaries', () => {
  const r = new Date('2026-09-01T08:00:00Z').getTime();
  const c = { status: 'in_production' as const, receivedAt: new Date(r).toISOString(), dueAt: new Date(r + 48 * HOUR_MS).toISOString(), deliveredAt: null };

  it('Received → +48 hours → Due', () => {
    expect(new Date(c.dueAt).getTime() - r).toBe(48 * HOUR_MS);
    expect(getSlaInfo(c, r + 47 * HOUR_MS + 59 * 60_000).state).toBe('critical');
    expect(getSlaInfo(c, r + 48 * HOUR_MS).state).toBe('overdue'); // exactly at due_at the promise is broken
    expect(getSlaInfo(c, r + 48 * HOUR_MS).remainingMs).toBe(0);
  });

  it('on time includes delivery at exactly due_at; one second later is late', () => {
    expect(deliveredOnTime({ ...c, deliveredAt: c.dueAt })).toBe(true);
    expect(deliveredOnTime({ ...c, deliveredAt: new Date(r + 48 * HOUR_MS + 1000).toISOString() })).toBe(false);
    expect(deliveredOnTime(c)).toBeNull();
    expect(onTimeRate([{ ...c, deliveredAt: c.dueAt }, { ...c, deliveredAt: new Date(r + 60 * HOUR_MS).toISOString() }, c])).toBe(0.5);
  });
});

describe('48-hour SLA — stored timestamps through the API (time travel)', () => {
  it('a real case goes on track → at risk → overdue, is listed, counted and notified', async () => {
    vi.useRealTimers(); // replace the advancing test clock with a frozen one
    vi.useFakeTimers({ toFake: ['Date'] });
    const t0 = new Date('2026-09-10T08:00:00Z').getTime();
    vi.setSystemTime(t0);
    await loginAs('sagal@48hrs.lab');
    const c = await caseService.create(base);
    expect(c.dueAt).toBe(new Date(t0 + 48 * HOUR_MS).toISOString());

    // Sessions last 8 hours, so sign in again after each jump (expiry is itself enforced).
    vi.setSystemTime(t0 + 20 * HOUR_MS);
    await loginAs('omar@48hrs.lab');
    expect((await caseService.list({ sla: 'on_track', perPage: 500 })).data.some((x) => x.id === c.id)).toBe(true);

    vi.setSystemTime(t0 + 40 * HOUR_MS);
    await loginAs('omar@48hrs.lab');
    expect((await caseService.list({ sla: 'at_risk', perPage: 500 })).data.some((x) => x.id === c.id)).toBe(true);

    vi.setSystemTime(t0 + 49 * HOUR_MS);
    await loginAs('omar@48hrs.lab');
    const overdue = await caseService.list({ sla: 'overdue', perPage: 500 });
    expect(overdue.data.some((x) => x.id === c.id)).toBe(true);
    const dash = await reportService.dashboard('today');
    expect(dash.overdue).toBeGreaterThan(0);
    const notes = await notificationService.list({ perPage: 200 });
    expect(notes.data.some((n) => n.type === 'case_overdue' && n.caseId === c.id)).toBe(true);
    expect(notes.data.some((n) => n.type === 'deadline_approaching' && n.caseId === c.id)).toBe(true);
    // The scanner notifies once, not on every request.
    await caseService.list({});
    const again = await notificationService.list({ perPage: 200 });
    expect(again.data.filter((n) => n.type === 'case_overdue' && n.caseId === c.id)).toHaveLength(1);
  });

  it('records "delivered on time" vs "delivered late" from the stored delivery time', async () => {
    vi.useRealTimers(); // replace the advancing test clock with a frozen one
    vi.useFakeTimers({ toFake: ['Date'] });
    const t0 = new Date('2026-09-10T08:00:00Z').getTime();
    vi.setSystemTime(t0);
    await loginAs('sagal@48hrs.lab');
    const onTime = await caseService.create(base);
    const late = await caseService.create(base);
    await toReady(onTime.id);
    await toReady(late.id);

    vi.setSystemTime(t0 + 30 * HOUR_MS);
    await loginAs('bashir@48hrs.lab');
    const a = await caseService.action(onTime.id, { action: 'deliver', delivery: { method: 'clinic_pickup', receivedBy: 'Nurse Hawa' } });
    vi.setSystemTime(t0 + 52 * HOUR_MS);
    await loginAs('bashir@48hrs.lab');
    const b = await caseService.action(late.id, { action: 'deliver', delivery: { method: 'clinic_pickup', receivedBy: 'Nurse Hawa' } });

    expect(getSlaInfo(a).state).toBe('met');
    expect(getSlaInfo(a).turnaroundMs).toBe(30 * HOUR_MS);
    expect(getSlaInfo(b).state).toBe('late');
    // Frozen at delivery: time passing afterwards changes nothing.
    vi.setSystemTime(t0 + 500 * HOUR_MS);
    expect(getSlaInfo(a, Date.now()).state).toBe('met');
    expect(a.deliveries[0]).toMatchObject({ status: 'delivered', receivedBy: 'Nurse Hawa', recordedByName: 'Bashir Omar' });
    expect(a.deliveredAt).toBe(new Date(t0 + 30 * HOUR_MS).toISOString());
  });
});

describe('workflow permission matrix', () => {
  const CASES: Record<CaseActionKey, 'submitted' | 'received' | 'assigned' | 'in_production' | 'quality_control' | 'ready' | 'delivered' | 'rework' | 'correction'> = {
    accept: 'submitted', request_correction: 'submitted', reject: 'submitted', resubmit: 'correction', start_review: 'received', assign: 'received',
    start_production: 'assigned', submit_qc: 'in_production', start_rework: 'rework', qc_pass: 'quality_control', qc_fail: 'quality_control',
    dispatch: 'ready', deliver: 'ready', confirm_receipt: 'delivered', cancel: 'received',
  };
  const payloadFor = (action: CaseActionKey) => ({
    action, note: 'x', technicianId: 'tec_fatima', qc: { issues: ['shade' as const], notes: 'x' },
    delivery: { method: 'lab_courier' as const, courierName: 'B', receivedBy: 'R' },
  });

  it.each(Object.keys(CASE_ACTIONS) as CaseActionKey[])('%s is refused for a role without its permission', async (action) => {
    const def = CASE_ACTIONS[action];
    const denied = DEFAULT_ROLES.find((r) => !r.permissions.includes(def.permission) && r.permissions.includes('cases.view_all'));
    const email = { reception: 'sagal@48hrs.lab', lab_manager: 'omar@48hrs.lab', qc: 'idil@48hrs.lab', delivery: 'bashir@48hrs.lab', admin: 'hodan@48hrs.lab' }[denied!.key as string];
    expect(email).toBeTruthy();
    const target = getDb().cases.find((c) => c.status === CASES[action]);
    expect(target, `seed has a ${CASES[action]} case`).toBeTruthy();
    await loginAs(email!);
    expect(await status(caseService.action(target!.id, payloadFor(action)))).toBe(403);
    expect(getDb().cases.find((c) => c.id === target!.id)!.status).toBe(CASES[action]);
  });

  it('persists the status history of every transition', async () => {
    await loginAs('sagal@48hrs.lab');
    const c = await caseService.create(base);
    await loginAs('omar@48hrs.lab');
    await caseService.action(c.id, { action: 'start_review' });
    await toReady(c.id);
    await loginAs('bashir@48hrs.lab');
    await caseService.action(c.id, { action: 'dispatch', delivery: { method: 'lab_courier', courierName: 'Bashir Omar' } });
    const d = await caseService.action(c.id, { action: 'deliver', delivery: { method: 'lab_courier', receivedBy: 'Nurse Hawa', notes: 'Left at front desk' } });
    expect(d.history.map((h) => h.toStatus)).toEqual(['received', 'review', 'assigned', 'in_production', 'quality_control', 'ready', 'out_for_delivery', 'delivered']);
    expect(d.history.every((h) => h.userName && h.createdAt)).toBe(true);
    expect(d.deliveries[0]).toMatchObject({ notes: 'Left at front desk', courierName: 'Bashir Omar', receivedBy: 'Nurse Hawa' });
    // Persisted: survives a reload of the mock database from storage.
    const stored = JSON.parse(localStorage.getItem('48hrs.mockdb')!) as { history: { caseId: string }[] };
    expect(stored.history.filter((h) => h.caseId === c.id)).toHaveLength(8);
  });
});

describe('transition integrity', () => {
  it('a rejected acceptance leaves the case untouched (no clock, no invoice, no payment)', async () => {
    await loginAs('sagal@48hrs.lab');
    const sub = getDb().cases.find((c) => c.status === 'submitted')!;
    const before = { receivedAt: sub.receivedAt, invoiceId: sub.invoiceId, payments: getDb().payments.length };
    const err = await caseService.action(sub.id, { action: 'accept', payment: { amount: 99999, method: 'bank_transfer' } }).catch((e: ApiError) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).fieldErrors['payment.amount']).toBeTruthy();
    expect((err as ApiError).fieldErrors['payment.reference']).toBeTruthy();
    const after = getDb().cases.find((c) => c.id === sub.id)!;
    expect(after.status).toBe('submitted');
    expect({ receivedAt: after.receivedAt, invoiceId: after.invoiceId, payments: getDb().payments.length }).toEqual(before);
  });

  it('shares input rules between UI and API', () => {
    expect(validateActionInput({ action: 'cancel' })).toEqual({ note: 'Please give a reason.' });
    expect(validateActionInput({ action: 'qc_fail', qc: { issues: [], notes: '' } })).toEqual({ note: 'Describe what must be reworked.', 'qc.issues': 'Select at least one issue.' });
    expect(validateActionInput({ action: 'dispatch', delivery: { method: 'clinic_pickup' } })).toEqual({});
    expect(validateActionInput({ action: 'deliver', delivery: { method: 'lab_courier' } })).toEqual({ 'delivery.receivedBy': 'Enter who received the case.' });
    expect(validateActionInput({ action: 'accept', payment: { amount: 50, method: 'cash' } }, { maxPayment: 40 })['payment.amount']).toMatch(/cannot exceed/);
  });
});

describe('payments through the API', () => {
  it('Total − Paid = Remaining, with Unpaid → Partial → Paid and Overdue after the due date', async () => {
    vi.useRealTimers(); // replace the advancing test clock with a frozen one
    vi.useFakeTimers({ toFake: ['Date'] });
    const t0 = new Date('2026-09-10T08:00:00Z').getTime();
    vi.setSystemTime(t0);
    await loginAs('sagal@48hrs.lab');
    const c = await caseService.create({ ...base, teeth: [14, 15, 16] });
    const inv = c.invoice!;
    expect(inv).toMatchObject({ total: 60, paid: 0, remaining: 60, status: 'unpaid' });

    vi.setSystemTime(t0 + 20 * DAY); // past the 14-day terms with nothing paid
    await loginAs('sagal@48hrs.lab');
    expect((await invoiceService.get(inv.id)).status).toBe('overdue');
    let v = await paymentService.record(inv.id, { amount: 20, method: 'cash' });
    expect(v).toMatchObject({ paid: 20, remaining: 40, status: 'overdue' });
    v = await paymentService.record(inv.id, { amount: 40, method: 'mobile_money', reference: 'MM-1' });
    expect(v).toMatchObject({ paid: 60, remaining: 0, status: 'paid' });

    vi.setSystemTime(t0);
    await loginAs('sagal@48hrs.lab');
    const c2 = await caseService.create(base);
    const partial = await paymentService.record(c2.invoice!.id, { amount: 5, method: 'cash' });
    expect(partial).toMatchObject({ total: 20, paid: 5, remaining: 15, status: 'partial' });
    // The case list reads the same derived status.
    const row = (await caseService.list({ search: c2.caseNumber })).data[0];
    expect(row.paymentStatus).toBe('partial');
  });
});

describe('dashboard period', () => {
  it('scopes new/completed/revenue to the selected period; live counts do not change', async () => {
    await loginAs('hodan@48hrs.lab');
    const today = await reportService.dashboard('today');
    const month = await reportService.dashboard('30d');
    expect(today.period).toBe('today');
    expect(month.newCases).toBeGreaterThanOrEqual(today.newCases);
    expect(month.completedInPeriod).toBeGreaterThanOrEqual(today.completedInPeriod);
    expect(month.revenue!).toBeGreaterThanOrEqual(today.revenue!);
    expect(month.activeCases).toBe(today.activeCases);
    expect(month.overdue).toBe(today.overdue);
  });

  it('hides money from roles without reports.financial', async () => {
    await loginAs('omar@48hrs.lab');
    const d = await reportService.dashboard();
    expect(d.revenue).toBeNull();
    expect(d.outstanding).toBeNull();
  });
});

describe('session expiry', () => {
  it('the API rejects a token once its lifetime has passed', async () => {
    vi.useRealTimers(); // replace the advancing test clock with a frozen one
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-09-10T08:00:00Z'));
    await loginAs('sagal@48hrs.lab');
    await caseService.list({});
    vi.setSystemTime(new Date('2026-09-10T16:01:00Z'));
    expect(await status(caseService.list({}))).toBe(401);
  });
});

describe('technician delete', () => {
  it('refuses technicians with case history, deletes unused ones', async () => {
    await loginAs('omar@48hrs.lab'); // lab manager may create and edit technicians, not delete them
    const t = await technicianService.create({ name: 'New Tech', email: 'new.tech@48hrs.lab', phone: '+252 61 000 0000', specialty: 'Ceramics', active: true });
    expect(await status(technicianService.remove(t.id))).toBe(403);
    await loginAs('hodan@48hrs.lab');
    expect(await status(technicianService.remove('tec_fatima'))).toBe(422);
    await technicianService.remove(t.id);
    await loginAs('sagal@48hrs.lab'); // reception can view but not manage technicians
    expect(await status(technicianService.remove('tec_ali'))).toBe(403);
  });
});
