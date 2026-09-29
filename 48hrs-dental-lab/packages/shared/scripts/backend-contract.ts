/**
 * The contract between these TypeScript rules (used by the React app) and the
 * Laravel backend, as data:
 *   - demo.json          the demo dataset, timestamps as offsets from "now"
 *   - shared-rules.json  catalogues + expected outputs of every shared rule
 * The Laravel seeder loads the first; the PHP parity tests assert the second,
 * so the backend can never drift from the rules the UI shows. Regenerate with
 * `npm run contract:export`; the shared test suite fails when they are stale.
 */
import { buildDashboard, caseReport, clinicReport, dashboardWindowStart, financialReport, periodStartDay, productionReport, relationStats, revenueWindowMonth, technicianReport, technicianWorkload, validateReportRange, DASHBOARD_PERIODS } from '../src/analytics';
import { DENTURE_TYPES, invoiceStatus, priceCase, round2, unitsFor, validatePaymentAmount, paymentReferenceRequired } from '../src/billing';
import { fromCaseRequest, toRequestErrors } from '../src/case-requests';
import { caseNumber, invoiceNumber, patientCode } from '../src/case-keys';
import * as C from '../src/constants';
import { dayFnFor, endOfDayExclusive, isDay, shiftDay, shiftMonth, startOfDay } from '../src/dates';
import { buildDemoDataset } from '../src/demo-data';
import { API_ERRORS } from '../src/errors';
import { deadlineAlert, notify, RECIPIENTS } from '../src/notifications';
import { ALL_PERMISSION_KEYS, DEFAULT_ROLES, PERMISSION_CATALOGUE, ROLE_LABELS, ROLE_ORDER } from '../src/permissions';
import * as S from '../src/schemas';
import { DEFAULT_SLA_CONFIG, getSlaInfo, HOUR_MS, onTimeRate } from '../src/sla';
import type { CaseActionKey, CaseActionPayload, CaseStatus, Invoice, LabCase } from '../src/types';
import { passwordSchema } from '../src/validation';
import { ACTION_ENDPOINT, ALL_STATUSES, CASE_ACTIONS, DONE_STATUSES, IN_LAB_STATUSES, OPEN_STATUSES, PRODUCTION_STATUSES, STATUS_META, canPerformAction, canViewCase, resolveTransition, validateActionInput, type Actor } from '../src/workflow';

/** Reference instant for fixtures: Monday 2026-06-15 12:00 in Africa/Mogadishu. */
export const REF_NOW = Date.parse('2026-06-15T09:00:00.000Z');
export const LAB_TZ = 'Africa/Mogadishu';
const ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?Z$/;

/* ------------------------------- demo.json ------------------------------ */

function relative(v: unknown, now: number): unknown {
  if (typeof v === 'string' && ISO.test(v)) return { at: Date.parse(v) - now };
  if (Array.isArray(v)) return v.map((x) => relative(x, now));
  if (v && typeof v === 'object') return Object.fromEntries(Object.entries(v).map(([k, x]) => [k, relative(x, now)]));
  return v;
}

export function demoExport() {
  const data = buildDemoDataset(REF_NOW);
  return { referenceNow: new Date(REF_NOW).toISOString(), numberYear: new Date(REF_NOW).getFullYear(), dataset: relative(data, REF_NOW) };
}

/* --------------------------- shared-rules.json -------------------------- */

const zodErrors = (schema: { safeParse: (v: unknown) => { success: boolean; data?: unknown; error?: unknown } }, body: unknown) => {
  const r = schema.safeParse(body);
  return r.success ? { ok: true, data: r.data } : { ok: false, errors: S.fieldErrors(r.error as never) };
};

function actors() {
  const out: Record<string, Actor> = {};
  for (const role of DEFAULT_ROLES) {
    const permissions = role.permissions;
    const base = { id: `usr_${role.key}`, role: role.key };
    if (role.key === 'client') {
      out.client_own = { user: { ...base, clinicId: 'cln_a', technicianId: null }, permissions };
    } else if (role.key === 'technician') {
      out.technician_own = { user: { ...base, clinicId: null, technicianId: 'tec_a' }, permissions };
    } else {
      out[role.key] = { user: { ...base, clinicId: null, technicianId: null }, permissions };
    }
  }
  // Roles are editable at runtime: with cases.view_all, only the ownership rules stop a technician or a clinic user.
  const tech = DEFAULT_ROLES.find((r) => r.key === 'technician')!.permissions;
  const client = DEFAULT_ROLES.find((r) => r.key === 'client')!.permissions;
  out.technician_viewall = { user: { id: 'usr_tv', role: 'technician', clinicId: null, technicianId: 'tec_a' }, permissions: [...tech, 'cases.view_all'] };
  out.client_viewall = { user: { id: 'usr_cv', role: 'client', clinicId: 'cln_a', technicianId: null }, permissions: [...client, 'cases.view_all'] };
  out.no_scope = { user: { id: 'usr_ns', role: 'reception', clinicId: null, technicianId: null }, permissions: ['cases.view', 'cases.update_status', 'cases.confirm_receipt'] };
  return out;
}

const CASE_REFS = {
  a: { technicianId: 'tec_a', clinicId: 'cln_a' },
  b: { technicianId: 'tec_b', clinicId: 'cln_b' },
  none: { technicianId: null, clinicId: 'cln_a' },
} as const;

function actionPayloads(): { payload: CaseActionPayload; maxPayment?: number }[] {
  return [
    { payload: { action: 'accept' } },
    { payload: { action: 'accept', payment: { amount: 10, method: 'cash' } }, maxPayment: 40 },
    { payload: { action: 'accept', payment: { amount: 0, method: 'cash' } }, maxPayment: 40 },
    { payload: { action: 'accept', payment: { amount: 41, method: 'mobile_money' } }, maxPayment: 40 },
    { payload: { action: 'accept', payment: { amount: 40.004, method: 'mobile_money', reference: ' ' } }, maxPayment: 40 },
    { payload: { action: 'accept', payment: { amount: 12.5, method: 'bank_transfer', reference: 'TRX-1' } }, maxPayment: 40 },
    { payload: { action: 'request_correction' } },
    { payload: { action: 'request_correction', note: '  ' } },
    { payload: { action: 'request_correction', note: 'Wrong shade' } },
    { payload: { action: 'reject', note: 'Duplicate' } },
    { payload: { action: 'assign' } },
    { payload: { action: 'assign', technicianId: 'tec_a' } },
    { payload: { action: 'qc_fail', qc: { issues: [], notes: '' } } },
    { payload: { action: 'qc_fail', note: '', qc: { issues: ['shade'], notes: '' } } },
    { payload: { action: 'qc_fail', qc: { issues: ['shade'], notes: 'Too light' } } },
    { payload: { action: 'qc_pass', qc: { issues: [], notes: '' } } },
    { payload: { action: 'dispatch', delivery: { method: 'lab_courier' } } },
    { payload: { action: 'dispatch', delivery: { method: 'clinic_pickup' } } },
    { payload: { action: 'dispatch', delivery: { method: 'third_party', courierName: 'DHL' } } },
    { payload: { action: 'deliver', delivery: { method: 'clinic_pickup' } } },
    { payload: { action: 'deliver', delivery: { method: 'clinic_pickup', receivedBy: '  ' } } },
    { payload: { action: 'deliver', delivery: { method: 'lab_courier', receivedBy: 'Nurse' } } },
    { payload: { action: 'cancel' } },
    { payload: { action: 'cancel', note: 'Patient moved' } },
    { payload: { action: 'start_review' } },
  ];
}

function requestCases() {
  const out: { endpoint: string; body: object; current: CaseStatus; payload: CaseActionPayload | null }[] = [];
  const bodies: [string, object][] = [
    ['status', { status: 'received' }],
    ['status', { status: 'received', payment: { amount: 5, method: 'cash' } }],
    ['status', { status: 'review', note: 'x' }],
    ['status', { status: 'assigned' }],
    ['status', { status: 'in_production' }],
    ['status', { status: 'quality_control', note: 'Glazed' }],
    ['status', { status: 'delivered' }],
    ['status', { status: 'completed' }],
    ['status', { status: 'cancelled', note: 'y' }],
    ['status', { status: 'correction', note: 'z' }],
    ['status', { status: 'submitted' }],
    ['status', { status: 'rejected', note: 'no' }],
    ['assign', { technicianId: 'tec_a', note: 'n' }],
    ['qc', { result: 'pass', issues: [], notes: '' }],
    ['qc', { result: 'fail', issues: ['fit'], notes: 'loose' }],
    ['rework', { note: 'go' }],
    ['delivery', { status: 'out_for_delivery', method: 'lab_courier', courierName: 'B' }],
    ['delivery', { status: 'delivered', method: 'clinic_pickup', receivedBy: 'R', notes: 'N' }],
  ];
  for (const [endpoint, body] of bodies) for (const current of ALL_STATUSES) out.push({ endpoint, body, current, payload: fromCaseRequest(endpoint as never, body as never, current) });
  return out;
}

function slaCases() {
  const H = HOUR_MS;
  const now = REF_NOW;
  const received = now - 30 * H;
  const due = received + 48 * H;
  const iso = (t: number | null) => (t === null ? null : new Date(t).toISOString());
  const rows: { input: { status: CaseStatus; receivedAt: string | null; dueAt: string | null; deliveredAt: string | null }; now: number }[] = [];
  for (const status of ALL_STATUSES) {
    for (const [r, d, del] of [
      [null, null, null],
      [received, due, null],
      [received, null, null],
      [now - 40 * H, now + 8 * H, null],
      [now - 45 * H, now + 3 * H, null],
      [now - 36 * H, now + 12 * H, null],
      [now - 36 * H, now + 12 * H + 1, null],
      [now - 48 * H, now, null],
      [now - 60 * H, now - 12 * H, null],
      [now - 60 * H, now - 12 * H, now - 20 * H],
      [now - 60 * H, now - 12 * H, now - 5 * H],
      [now - 10 * H, now - 10 * H, null],
    ] as const) {
      rows.push({ input: { status, receivedAt: iso(r), dueAt: iso(d), deliveredAt: iso(del) }, now });
    }
  }
  return rows.map((x) => {
    const info = getSlaInfo(x.input, x.now, DEFAULT_SLA_CONFIG);
    return { ...x, expected: { state: info.state, label: info.label, remainingMs: info.remainingMs, progress: info.progress, turnaroundMs: info.turnaroundMs, dueAt: info.dueAt ? info.dueAt.toISOString() : null } };
  });
}

type Row = LabCase;

function analyticsFixtures() {
  const data = buildDemoDataset(REF_NOW);
  const dayOf = dayFnFor(LAB_TZ);
  const now = REF_NOW;
  const sla = { slaHours: data.settings.slaHours, atRiskHours: data.settings.atRiskHours, criticalHours: data.settings.criticalHours };
  const paid = new Map<string, number>();
  data.payments.forEach((p) => paid.set(p.invoiceId, round2((paid.get(p.invoiceId) ?? 0) + p.amount)));
  const invoiceFig = (inv: (typeof data.invoices)[number]) => {
    const p = paid.get(inv.id) ?? 0;
    return { total: inv.total, paid: p, remaining: Math.max(0, round2(inv.total - p)), status: invoiceStatus(inv.total, p, inv.dueDate, now) };
  };
  const invoiceByCase = new Map(data.invoices.map((i) => [i.caseId, invoiceFig(i)]));
  const cases: Row[] = data.cases.map((c) => ({ ...c, paymentStatus: invoiceByCase.get(c.id)?.status ?? 'unpaid' }));
  const slaFields = (c: Row) => ({ status: c.status, receivedAt: c.receivedAt ?? null, dueAt: c.dueAt ?? null, deliveredAt: c.deliveredAt ?? null, submittedAt: c.submittedAt ?? null, createdAt: c.createdAt });

  const dashboards = DASHBOARD_PERIODS.flatMap((period) =>
    [true, false].map((withFinance) => {
      const today = dayOf(now);
      const since = startOfDay(dashboardWindowStart(period, today), LAB_TZ).getTime();
      const periodStart = periodStartDay(period, today);
      const revenueStart = `${revenueWindowMonth(today)}-01`;
      const moneySince = startOfDay(periodStart < revenueStart ? periodStart : revenueStart, LAB_TZ).getTime();
      const statusCounts: Partial<Record<CaseStatus, number>> = {};
      cases.forEach((c) => (statusCounts[c.status] = (statusCounts[c.status] ?? 0) + 1));
      const t = (v?: string | null) => (v ? Date.parse(v) : -Infinity);
      const input = {
        now,
        period,
        statusCounts,
        inLab: cases.filter((c) => IN_LAB_STATUSES.includes(c.status)).map(slaFields),
        recent: cases.filter((c) => t(c.createdAt) >= since || t(c.submittedAt) >= since || t(c.receivedAt) >= since || t(c.deliveredAt) >= since).map(slaFields),
        finance: withFinance
          ? {
              invoices: data.invoices.filter((i) => Date.parse(i.issuedAt) >= moneySince).map((i) => ({ issuedAt: i.issuedAt, total: i.total })),
              payments: data.payments.filter((p) => Date.parse(p.paidAt) >= moneySince).map((p) => ({ paidAt: p.paidAt, amount: p.amount })),
              outstanding: round2([...invoiceByCase.values()].reduce((s, f) => s + f.remaining, 0)),
            }
          : null,
      };
      return { input, expected: buildDashboard({ ...input, dayOf, sla }) };
    }),
  );

  const filters = [
    { from: shiftDay(dayOf(now), -95), to: dayOf(now) },
    { from: shiftDay(dayOf(now), -30), to: dayOf(now), technicianId: 'tec_fatima' },
    { from: shiftDay(dayOf(now), -60), to: shiftDay(dayOf(now), -10), clinicId: 'cln_banadir' },
    { from: shiftDay(dayOf(now), -95), to: dayOf(now), status: 'delivered' as CaseStatus },
    { from: shiftDay(dayOf(now), -95), to: dayOf(now), caseType: 'denture' as const },
    { from: dayOf(now), to: dayOf(now) },
  ];
  const failures = new Map<string, number>();
  data.qualityChecks.filter((q) => q.result === 'failed').forEach((q) => failures.set(q.caseId, (failures.get(q.caseId) ?? 0) + 1));
  const technicians = [...data.technicians].sort((a, b) => a.name.localeCompare(b.name)).map((t) => ({ id: t.id, name: t.name }));
  const clinics = [...data.clinics].sort((a, b) => a.name.localeCompare(b.name)).map((k) => ({ id: k.id, name: k.name }));
  const reports = filters.map((f) => {
    const rows = cases.filter((c) => {
      if (c.status === 'rejected') return false;
      const d = dayOf(c.receivedAt ?? c.createdAt);
      return d >= f.from && d <= f.to && (!f.technicianId || c.technicianId === f.technicianId) && (!f.clinicId || c.clinicId === f.clinicId) && (!f.status || c.status === f.status) && (!f.caseType || c.caseType === f.caseType);
    });
    const input = { filters: f, cases: rows, now, sla, dayOf };
    const inv = new Map(rows.filter((c) => invoiceByCase.has(c.id)).map((c) => [c.id, invoiceByCase.get(c.id)!]));
    return {
      filters: f,
      caseIds: rows.map((c) => c.id),
      technicians,
      clinics,
      qcFailures: Object.fromEntries([...failures].filter(([id]) => rows.some((c) => c.id === id))),
      expected: {
        cases: caseReport(input),
        production: productionReport(input),
        technicians: technicianReport(input, technicians, failures),
        clinics: clinicReport(input, clinics),
        financial: financialReport(input, inv),
      },
    };
  });

  const relation = ['cln_smile', 'cln_banadir'].map((clinicId) => {
    const rows = cases.filter((c) => c.clinicId === clinicId);
    return { clinicId, caseIds: rows.map((c) => c.id), expected: relationStats(rows, new Map(rows.filter((c) => invoiceByCase.has(c.id)).map((c) => [c.id, invoiceByCase.get(c.id)!])), now, sla) };
  });
  const workloads = data.technicians.map((t) => {
    const rows = cases.filter((c) => c.technicianId === t.id);
    return { technicianId: t.id, caseIds: rows.map((c) => c.id), expected: technicianWorkload(rows, now, sla, dayOf) };
  });
  return {
    now,
    timezone: LAB_TZ,
    sla,
    cases,
    invoices: Object.fromEntries([...invoiceByCase].map(([k, v]) => [k, v as Pick<Invoice, 'total' | 'paid' | 'remaining' | 'status'>])),
    dashboards,
    reports,
    relation,
    workloads,
    onTimeRate: onTimeRate(cases),
    reportRanges: [
      ['2026-01-01', '2026-01-31'],
      ['2026-02-01', '2026-01-31'],
      ['', '2026-01-31'],
      ['2026-02-30', '2026-03-01'],
      ['2026-1-01', '2026-01-31'],
    ].map(([from, to]) => ({ from, to, expected: validateReportRange(from, to) })),
  };
}

function dateCases() {
  const zones = ['Africa/Mogadishu', 'UTC', 'America/New_York', 'Europe/London', 'Pacific/Kiritimati', 'Asia/Kolkata'];
  const instants = [REF_NOW, Date.parse('2026-03-08T06:59:00Z'), Date.parse('2026-03-08T07:00:00Z'), Date.parse('2026-10-25T00:59:00Z'), Date.parse('2026-12-31T20:59:59.999Z'), Date.parse('2026-12-31T21:00:00Z')];
  const days = ['2026-06-15', '2026-03-08', '2026-11-01', '2026-10-25', '2024-02-29', '2026-12-31'];
  return {
    dayIn: zones.flatMap((tz) => instants.map((t) => ({ tz, t, day: dayFnFor(tz)(t) }))),
    startOfDay: zones.flatMap((tz) => days.map((d) => ({ tz, day: d, start: startOfDay(d, tz).toISOString(), end: endOfDayExclusive(d, tz).toISOString() }))),
    shiftDay: days.flatMap((d) => [-400, -29, -1, 0, 1, 31].map((n) => ({ day: d, n, out: shiftDay(d, n) }))),
    shiftMonth: days.flatMap((d) => [-13, -5, 0, 1, 12].map((n) => ({ day: d, n, out: shiftMonth(d, n) }))),
    monthLabels: Array.from({ length: 12 }, (_, i) => `2027-${String(i + 1).padStart(2, '0')}`).map((m) => ({ m, label: new Date(`${m}-01T12:00:00Z`).toLocaleDateString('en-GB', { month: 'short', year: '2-digit', timeZone: 'UTC' }) })),
    isDay: ['2026-06-15', '2026-02-29', '2024-02-29', '2026-13-01', '2026-6-15', 'x', '', '2026-06-15T00:00:00Z'].map((v) => ({ v, out: isDay(v) })),
  };
}

function validationCases() {
  const cases: [string, unknown][] = [
    ['login', {}],
    ['login', { email: 'NOT-an-email', password: '' }],
    ['login', { email: ' Sagal@48HRS.lab ', password: 'x' }],
    ['forgotPassword', { email: '' }],
    ['resetPassword', { token: '', email: 'a@b.co', password: 'short', passwordConfirmation: 'x' }],
    ['resetPassword', { token: 't', email: 'a@b.co', password: 'longenough1', passwordConfirmation: 'longenough2' }],
    ['resetPassword', { token: 't', email: 'a@b.co', password: 'onlyletters', passwordConfirmation: 'onlyletters' }],
    ['createCase', {}],
    ['createCase', { doctorId: 'd', serviceId: 's', shade: 'A2', priority: 'urgent', teeth: [0, 33, 1.5], dentureType: 'nope' }],
    ['createCase', { doctorId: ' d ', serviceId: 's', shade: ' A2 ', priority: 'normal', teeth: [3, 3, 1], clinicId: 'c', receiveNow: false, newPatient: { name: ' New ', phone: 'bad' } }],
    ['createCase', { doctorId: 'd', serviceId: 's', shade: 'A2', priority: 'normal', dueAt: '2026-06-20' }],
    ['updateCase', { shade: '', teeth: [] }],
    ['updateCase', { priority: 'high', dentureType: null }],
    ['caseNote', {}],
    ['caseNote', { text: '   ' }],
    ['caseStatus', { status: 'nope' }],
    ['caseStatus', { status: 'received', payment: { amount: 'abc', method: 'bitcoin' } }],
    ['caseStatus', { status: 'received', payment: { amount: '12.5', method: 'cash' } }],
    ['caseAssign', {}],
    ['caseQc', { result: 'maybe', issues: ['nope'] }],
    ['caseQc', { result: 'fail' }],
    ['caseDelivery', { status: 'lost', method: 'drone' }],
    ['caseDelivery', { status: 'delivered', method: 'clinic_pickup', receivedBy: ' R ' }],
    ['clinic', {}],
    ['clinic', { name: 'K', phone: '12', email: 'bad', status: 'closed' }],
    ['clinic', { name: ' K ', phone: '+252 61 000 1111', email: '', status: 'active' }],
    ['doctor', { name: 'D', clinicId: '', phone: '+252 61 000 1111' }],
    ['patient', { name: '', dateOfBirth: '1990-13-01', gender: 'x', email: 'bad' }],
    ['patient', { name: 'P', dateOfBirth: '', gender: null, phone: '' }],
    ['patient', { name: 'P', dateOfBirth: '1990-02-30' }],
    ['patient', { name: 'P', dateOfBirth: '1990-13-40' }],
    ['patient', { name: 'P', dateOfBirth: 19900101 }],
    ['technician', { name: 'T', email: 'T@X.CO', phone: '+252 61 000 1111', specialty: '' }],
    ['recordPayment', {}],
    ['recordPayment', { invoiceId: 'i', amount: -1, method: 'cash', paidAt: '2026-01-01' }],
    ['recordPayment', { invoiceId: 'i', amount: '7', method: 'card', reference: ' R ', paidAt: '2026-01-01T10:00:00Z' }],
    ['createInvoice', {}],
    ['user', {}],
    ['user', { name: 'U', email: 'u@x.co', role: 'boss', password: 'short' }],
    ['user', { name: 'U', email: 'U@X.CO', role: 'reception', password: '', clinicId: '' }],
    ['userStatus', {}],
    ['rolePermissions', { permissions: 'x' }],
    ['service', { name: '', caseType: 'x', unitMode: 'y', unitPrice: -1, defaultMaterial: '' }],
    ['service', { name: 'S', caseType: 'crown', unitMode: 'tooth', unitPrice: '20', defaultMaterial: 'Z' }],
    ['settings', { labName: '', currency: 'usd', slaHours: 2, atRiskHours: 12, criticalHours: 12, emergencyFeePerUnit: -1, invoiceDueDays: 500 }],
    ['settings', { slaHours: -1 }],
    ['service', {}],
    ['recordPayment', { invoiceId: 'i', method: 'cash' }],
    ['settings', { labName: 'L', currency: 'USD', slaHours: 48, atRiskHours: 12, criticalHours: 4, emergencyFeePerUnit: 5, invoiceDueDays: 14 }],
  ];
  const schemas: Record<string, { safeParse: (v: unknown) => never }> = {
    login: S.loginSchema as never,
    forgotPassword: S.forgotPasswordSchema as never,
    resetPassword: S.resetPasswordSchema as never,
    createCase: S.createCaseSchema as never,
    updateCase: S.updateCaseSchema as never,
    caseNote: S.caseNoteSchema as never,
    caseStatus: S.caseStatusSchema as never,
    caseAssign: S.caseAssignSchema as never,
    caseQc: S.caseQcSchema as never,
    caseDelivery: S.caseDeliverySchema as never,
    clinic: S.clinicSchema as never,
    doctor: S.doctorSchema as never,
    patient: S.patientSchema as never,
    technician: S.technicianSchema as never,
    recordPayment: S.recordPaymentSchema as never,
    createInvoice: S.createInvoiceSchema as never,
    user: S.userSchema as never,
    userStatus: S.userStatusSchema as never,
    rolePermissions: S.rolePermissionsSchema as never,
    service: S.serviceSchema as never,
    settings: S.settingsSchema as never,
  };
  return cases.map(([schema, body]) => ({ schema, body, ...zodErrors(schemas[schema], body) }));
}

export function rulesExport() {
  const A = actors();
  const actions = Object.keys(CASE_ACTIONS) as CaseActionKey[];
  const matrix: Record<string, string> = {};
  const viewMatrix: Record<string, boolean> = {};
  for (const [actorKey, actor] of Object.entries(A)) {
    for (const [refKey, ref] of Object.entries(CASE_REFS)) {
      viewMatrix[`${actorKey}|${refKey}`] = canViewCase(actor, ref);
      matrix[`${actorKey}|${refKey}`] = actions.map((a) => ALL_STATUSES.map((s) => (canPerformAction(a, { ...ref, status: s }, actor) ? '1' : '0')).join('')).join(',');
    }
  }
  const H = HOUR_MS;
  return {
    referenceNow: REF_NOW,
    catalogue: {
      permissions: PERMISSION_CATALOGUE,
      allPermissionKeys: ALL_PERMISSION_KEYS,
      roles: DEFAULT_ROLES,
      roleLabels: ROLE_LABELS,
      roleOrder: ROLE_ORDER,
      statusMeta: STATUS_META,
      statuses: ALL_STATUSES,
      openStatuses: OPEN_STATUSES,
      inLabStatuses: IN_LAB_STATUSES,
      doneStatuses: DONE_STATUSES,
      productionStatuses: PRODUCTION_STATUSES,
      caseActions: CASE_ACTIONS,
      actionEndpoint: ACTION_ENDPOINT,
      caseTypeLabels: C.CASE_TYPE_LABELS,
      priorityMeta: C.PRIORITY_META,
      paymentMethodLabels: C.PAYMENT_METHOD_LABELS,
      deliveryMethodLabels: C.DELIVERY_METHOD_LABELS,
      attachmentCategoryLabels: C.ATTACHMENT_CATEGORY_LABELS,
      qcIssueLabels: C.QC_ISSUE_LABELS,
      allowedFileExtensions: C.ALLOWED_FILE_EXTENSIONS,
      maxFileMb: C.MAX_FILE_MB,
      defaultLabSettings: C.DEFAULT_LAB_SETTINGS,
      defaultSla: DEFAULT_SLA_CONFIG,
      dentureTypes: DENTURE_TYPES,
      apiErrors: API_ERRORS,
      recipients: RECIPIENTS,
    },
    workflow: {
      actions,
      actors: A,
      caseRefs: CASE_REFS,
      matrix,
      viewMatrix,
      transitions: ALL_STATUSES.flatMap((from) => ALL_STATUSES.map((to) => ({ from, to, action: resolveTransition(from, to) }))),
      inputs: actionPayloads().map((x) => ({ ...x, maxPayment: x.maxPayment ?? null, expected: validateActionInput(x.payload, { maxPayment: x.maxPayment }) })),
      requests: requestCases(),
      requestErrors: (['status', 'assign', 'qc', 'rework', 'delivery'] as const).map((endpoint) => ({
        endpoint,
        input: { note: ['n'], 'qc.issues': ['i'], 'delivery.method': ['m'], 'delivery.courierName': ['c'], 'delivery.receivedBy': ['r'], technicianId: ['t'], 'payment.amount': ['a'] },
        expected: toRequestErrors(endpoint, { note: ['n'], 'qc.issues': ['i'], 'delivery.method': ['m'], 'delivery.courierName': ['c'], 'delivery.receivedBy': ['r'], technicianId: ['t'], 'payment.amount': ['a'] }),
      })),
    },
    sla: slaCases(),
    billing: {
      units: [
        ['tooth', [1, 2, 3], null],
        ['tooth', [], null],
        ['denture', [], 'upper_lower'],
        ['denture', [], 'partial'],
        ['denture', [], null],
        ['arch', [1, 2], null],
      ].map(([mode, teeth, dt]) => ({ mode, teeth, dentureType: dt, expected: unitsFor({ unitMode: mode as never }, teeth as number[], dt as never) })),
      prices: [
        [{ unitMode: 'tooth', unitPrice: 20 }, [8, 9], null, false, 5],
        [{ unitMode: 'tooth', unitPrice: 19.99 }, [1, 2, 3], null, true, 5],
        [{ unitMode: 'denture', unitPrice: 120 }, [], 'upper_lower', true, 7.5],
        [{ unitMode: 'arch', unitPrice: 33.335 }, [], null, false, 5],
      ].map(([svc, teeth, dt, urgent, fee]) => ({ service: svc, teeth, dentureType: dt, urgent, fee, expected: priceCase(svc as never, teeth as number[], dt as never, urgent as boolean, fee as number) })),
      invoiceStatus: [
        [40, 0, REF_NOW + 1], [40, 0, REF_NOW - 1], [40, 10, REF_NOW + 1], [40, 10, REF_NOW - 1], [40, 40, REF_NOW - 1], [40, 39.999, REF_NOW + 1], [0, 0, REF_NOW + 1],
      ].map(([total, paid, due]) => ({ total, paid, dueDate: new Date(due).toISOString(), now: REF_NOW, expected: invoiceStatus(total, paid, new Date(due).toISOString(), REF_NOW) })),
      paymentAmount: [[10, 40], [0, 40], [-1, 40], [40, 40], [40.004, 40], [40.006, 40], [Number.NaN, 40], [1, 0], [12.345, 12.34]].map(([amount, remaining]) => ({ amount: Number.isNaN(amount) ? 'NaN' : amount, remaining, expected: validatePaymentAmount(amount, remaining) })),
      referenceRequired: ['cash', 'bank_transfer', 'mobile_money', 'card', 'other', '', null].map((m) => ({ method: m, expected: paymentReferenceRequired(m) })),
      round2: [0.005, 1.005, 2.675, 10.1 + 20.2, -1.235, 123456.785].map((n) => ({ n, expected: round2(n) })),
    },
    notifications: {
      templates: {
        caseReceived: notify.caseReceived({ caseNumber: 'DL-2026-00001' }),
        caseSubmitted: notify.caseSubmitted({ caseNumber: 'DL-2026-00001' }, 'Smile Dental'),
        caseAccepted: notify.caseAccepted({ caseNumber: 'DL-2026-00001' }),
        correctionRequested: notify.correctionRequested({ caseNumber: 'DL-2026-00001' }, 'Fix shade'),
        caseResubmitted: notify.caseResubmitted({ caseNumber: 'DL-2026-00001' }, 'Smile Dental'),
        caseRejected: notify.caseRejected({ caseNumber: 'DL-2026-00001' }, 'Dup'),
        caseAssigned: notify.caseAssigned({ caseNumber: 'DL-2026-00001', restorationType: 'Zirconia Crown' }, 31),
        caseAssignedNoDeadline: notify.caseAssigned({ caseNumber: 'DL-2026-00001', restorationType: 'Zirconia Crown' }, null),
        qcRequired: notify.qcRequired({ caseNumber: 'DL-2026-00001' }),
        qcFailed: notify.qcFailed({ caseNumber: 'DL-2026-00001' }, 'Too light'),
        caseReady: notify.caseReady({ caseNumber: 'DL-2026-00001' }),
        caseDispatched: notify.caseDispatched({ caseNumber: 'DL-2026-00001' }),
        caseDelivered: notify.caseDelivered({ caseNumber: 'DL-2026-00001' }, 'Front desk'),
        paymentReceived: notify.paymentReceived(12.5, 'INV-2026-00001'),
        caseOverdue: notify.caseOverdue({ caseNumber: 'DL-2026-00001' }, 48),
        deadlineApproaching1: notify.deadlineApproaching({ caseNumber: 'DL-2026-00001' }, 1),
        deadlineApproaching5: notify.deadlineApproaching({ caseNumber: 'DL-2026-00001' }, 5),
      },
      deadline: ALL_STATUSES.flatMap((status) =>
        [
          [REF_NOW - 30 * H, REF_NOW + 18 * H, {}],
          [REF_NOW - 40 * H, REF_NOW + 8 * H, {}],
          [REF_NOW - 40 * H, REF_NOW + 8 * H, { atRisk: true }],
          [REF_NOW - 47.6 * H, REF_NOW + 0.4 * H, {}],
          [REF_NOW - 49 * H, REF_NOW - 1 * H, {}],
          [REF_NOW - 49 * H, REF_NOW - 1 * H, { atRisk: true }],
          [REF_NOW - 49 * H, REF_NOW - 1 * H, { atRisk: true, overdue: true }],
          [null, null, {}],
        ].map(([r, d, flags]) => {
          const c = { caseNumber: 'DL-2026-00001', status, receivedAt: r ? new Date(r as number).toISOString() : null, dueAt: d ? new Date(d as number).toISOString() : null, deliveredAt: null };
          return { case: c, flags, expected: deadlineAlert(c, flags as never, REF_NOW, DEFAULT_SLA_CONFIG) };
        }),
      ),
    },
    keys: { caseNumber: caseNumber(2026, 7), invoiceNumber: invoiceNumber(2026, 123456), patientCode: patientCode(1024) },
    files: ['scan.STL', 'photo.jpeg', 'x.JPG', 'a.b.pdf', 'noext', '.hidden', 'file.exe', 'upper.stl.exe', 'dicom.dcm', 'doc.docx', 'trailing.'].map((name) => ({
      name,
      extension: C.extensionOf(name),
      category: C.categoryForExtension(C.extensionOf(name)),
      error: C.validateFile({ name, size: 10 }),
      tooBig: C.validateFile({ name, size: 60 * 1_048_576 }),
    })),
    password: ['short1', 'longenough', '12345678', 'Longenough1', ''].map((p) => ({ password: p, ...zodErrors(passwordSchema as never, p) })),
    dates: dateCases(),
    analytics: analyticsFixtures(),
    validation: validationCases(),
  };
}
