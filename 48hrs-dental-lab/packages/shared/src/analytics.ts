/**
 * Aggregations behind the dashboard, reports and directory statistics. Pure
 * functions over already-scoped rows, so the API (rows from PostgreSQL) and
 * the mock backend (rows from localStorage) produce identical numbers.
 */
import type {
  CaseReport,
  CaseStatus,
  CaseType,
  ClinicReport,
  DashboardPeriod,
  DashboardSummary,
  FinancialReport,
  Invoice,
  LabCase,
  Payment,
  ProductionReport,
  RelationStats,
  ReportFilters,
  ReportResult,
  TechnicianListItem,
  TechnicianReport,
} from './types';
import { round2 } from './billing';
import { type DayOf, isDay, shiftDay, shiftMonth } from './dates';
import { getSlaInfo, HOUR_MS, onTimeRate, type SlaConfig } from './sla';
import { DONE_STATUSES, IN_LAB_STATUSES, OPEN_STATUSES, PRODUCTION_STATUSES, STATUS_META } from './workflow';

export const round1 = (n: number) => Math.round(n * 10) / 10;
export const average = (xs: number[]) => (xs.length ? round1(xs.reduce((s, x) => s + x, 0) / xs.length) : null);

export function hoursBetween(a?: string | null, b?: string | null) {
  return a && b ? (new Date(b).getTime() - new Date(a).getTime()) / HOUR_MS : null;
}

const monthLabel = (key: string) => new Date(`${key}-01T12:00:00Z`).toLocaleDateString('en-GB', { month: 'short', year: '2-digit', timeZone: 'UTC' });

type SlaFields = Pick<LabCase, 'status' | 'receivedAt' | 'dueAt' | 'deliveredAt'>;

/* ------------------------------- Dashboard ------------------------------ */

export const DASHBOARD_PERIODS: DashboardPeriod[] = ['today', '7d', '30d', 'month'];

export function parsePeriod(v: unknown): DashboardPeriod {
  return DASHBOARD_PERIODS.includes(v as DashboardPeriod) ? (v as DashboardPeriod) : '30d';
}

export function periodStartDay(period: DashboardPeriod, today: string) {
  if (period === 'month') return `${today.slice(0, 8)}01`;
  return shiftDay(today, period === 'today' ? 0 : period === '7d' ? -6 : -29);
}

/** Earliest day the dashboard needs case rows for: the period start or the 14-day chart, whichever is older. */
export function dashboardWindowStart(period: DashboardPeriod, today: string) {
  const p = periodStartDay(period, today);
  const chart = shiftDay(today, -13);
  return p < chart ? p : chart;
}

/** First month (YYYY-MM) of the six-month revenue chart. */
export function revenueWindowMonth(today: string) {
  return shiftMonth(today, -5);
}

export interface DashboardInput {
  now: number;
  dayOf: DayOf;
  sla: SlaConfig;
  period: DashboardPeriod;
  /** Case counts per status across every case the user may see. */
  statusCounts: Partial<Record<CaseStatus, number>>;
  /** Visible cases in a lab status (the live 48-hour board). */
  inLab: SlaFields[];
  /** Visible cases created, submitted, received or delivered since dashboardWindowStart(). */
  recent: Pick<LabCase, 'status' | 'receivedAt' | 'submittedAt' | 'createdAt' | 'deliveredAt' | 'dueAt'>[];
  /** Present only for users with reports.financial. */
  finance: null | {
    /** Invoices issued since min(period start, revenueWindowMonth). */
    invoices: Pick<Invoice, 'issuedAt' | 'total'>[];
    /** Payments received over the same window. */
    payments: Pick<Payment, 'paidAt' | 'amount'>[];
    /** Remaining balance over every open invoice. */
    outstanding: number;
  };
}

export function buildDashboard(i: DashboardInput): DashboardSummary {
  const today = i.dayOf(i.now);
  const periodStart = periodStartDay(i.period, today);
  const inPeriod = (v?: string | null) => !!v && i.dayOf(v) >= periodStart;
  const n = (...s: CaseStatus[]) => s.reduce((sum, st) => sum + (i.statusCounts[st] ?? 0), 0);
  const sla = i.inLab.map((c) => ({ c, info: getSlaInfo(c, i.now, i.sla) }));
  const recentDone = i.recent.filter((c) => c.receivedAt && inPeriod(c.deliveredAt));

  const last14Days = Array.from({ length: 14 }, (_, k) => {
    const day = shiftDay(today, k - 13);
    return {
      date: day,
      received: i.recent.filter((c) => c.receivedAt && i.dayOf(c.receivedAt) === day).length,
      delivered: i.recent.filter((c) => c.deliveredAt && i.dayOf(c.deliveredAt) === day).length,
    };
  });

  let revenueByMonth: DashboardSummary['revenueByMonth'] = null;
  if (i.finance) {
    const f = i.finance;
    const monthOf = (v: string) => i.dayOf(v).slice(0, 7);
    revenueByMonth = Array.from({ length: 6 }, (_, k) => shiftMonth(today, k - 5)).map((m) => ({
      month: monthLabel(m),
      invoiced: round1(f.invoices.filter((x) => monthOf(x.issuedAt) === m).reduce((s, x) => s + x.total, 0)),
      collected: round1(f.payments.filter((p) => monthOf(p.paidAt) === m).reduce((s, p) => s + p.amount, 0)),
    }));
  }

  return {
    period: i.period,
    periodStart,
    activeCases: i.inLab.length,
    newCases: i.recent.filter((c) => inPeriod(c.receivedAt ?? c.submittedAt ?? c.createdAt) && c.status !== 'rejected').length,
    dueToday: sla.filter(({ c, info }) => c.dueAt && i.dayOf(c.dueAt) === today && info.state !== 'overdue').length,
    overdue: sla.filter(({ info }) => info.state === 'overdue').length,
    completed: n(...DONE_STATUSES),
    completedInPeriod: recentDone.length,
    inProduction: n(...PRODUCTION_STATUSES),
    pendingQc: n('quality_control'),
    readyForDelivery: n('ready', 'out_for_delivery'),
    awaitingAcceptance: n('submitted'),
    revenue: i.finance ? round1(i.finance.invoices.filter((x) => inPeriod(x.issuedAt)).reduce((s, x) => s + x.total, 0)) : null,
    collected: i.finance ? round1(i.finance.payments.filter((p) => inPeriod(p.paidAt)).reduce((s, p) => s + p.amount, 0)) : null,
    outstanding: i.finance ? round1(i.finance.outstanding) : null,
    performance: {
      onTime: sla.filter(({ info }) => info.state === 'on_track').length,
      atRisk: sla.filter(({ info }) => info.state === 'at_risk' || info.state === 'critical').length,
      overdue: sla.filter(({ info }) => info.state === 'overdue').length,
      onTimeRate: onTimeRate(recentDone),
      avgCompletionHours: average(recentDone.map((c) => hoursBetween(c.receivedAt, c.deliveredAt)!)),
    },
    last14Days,
    statusBreakdown: OPEN_STATUSES.map((status) => ({ status, count: n(status) })).filter((s) => s.count > 0),
    revenueByMonth,
  };
}

/* -------------------------------- Reports ------------------------------- */

/** Returns an error message for an unusable report range, or null. */
export function validateReportRange(from: unknown, to: unknown): string | null {
  if (!isDay(from) || !isDay(to)) return 'Choose a date range.';
  if (from > to) return 'The start date must be before the end date.';
  return null;
}

/** Does a case match the report filters (date range on receivedAt, falling back to createdAt)? */
export function matchesReportFilters(c: LabCase, f: ReportFilters, dayOf: DayOf) {
  if (c.status === 'rejected') return false;
  const d = dayOf(c.receivedAt ?? c.createdAt);
  if (d < f.from || d > f.to) return false;
  if (f.technicianId && c.technicianId !== f.technicianId) return false;
  if (f.doctorId && c.doctorId !== f.doctorId) return false;
  if (f.clinicId && c.clinicId !== f.clinicId) return false;
  if (f.status && c.status !== f.status) return false;
  if (f.caseType && c.caseType !== f.caseType) return false;
  return true;
}

export interface ReportInput {
  filters: ReportFilters;
  /** Visible cases matching matchesReportFilters(). */
  cases: LabCase[];
  now: number;
  sla: SlaConfig;
  dayOf: DayOf;
}

const MAX_DAILY_POINTS = 120;

function reportDays(f: ReportFilters) {
  const days: string[] = [];
  for (let d = f.from; d <= f.to && days.length < MAX_DAILY_POINTS; d = shiftDay(d, 1)) days.push(d);
  return days;
}

function isOverdueOrLate(c: SlaFields, now: number, sla: SlaConfig) {
  const s = getSlaInfo(c, now, sla).state;
  return s === 'overdue' || s === 'late';
}

export function caseReport({ filters, cases, now, sla, dayOf }: ReportInput): CaseReport {
  const days = reportDays(filters);
  const receivedDay = (c: LabCase) => dayOf(c.receivedAt ?? c.createdAt);
  const delivered = cases.filter((c) => c.deliveredAt && c.dueAt);
  const months = [...new Set(days.map((d) => d.slice(0, 7)))];
  const types = [...new Set(cases.map((c) => c.caseType))];
  return {
    totals: {
      cases: cases.length,
      completed: cases.filter((c) => DONE_STATUSES.includes(c.status)).length,
      open: cases.filter((c) => OPEN_STATUSES.includes(c.status)).length,
      overdue: cases.filter((c) => isOverdueOrLate(c, now, sla)).length,
      onTimeRate: onTimeRate(cases),
      avgTurnaroundHours: average(delivered.map((c) => hoursBetween(c.receivedAt, c.deliveredAt)!)),
    },
    daily: days.map((day) => ({
      label: day,
      received: cases.filter((c) => receivedDay(c) === day).length,
      completed: cases.filter((c) => c.deliveredAt && dayOf(c.deliveredAt) === day).length,
      overdue: cases.filter((c) => c.dueAt && dayOf(c.dueAt) === day && isOverdueOrLate(c, now, sla)).length,
    })),
    monthly: months.map((m) => {
      const inMonth = cases.filter((c) => receivedDay(c).slice(0, 7) === m);
      return {
        label: monthLabel(m),
        received: inMonth.length,
        completed: inMonth.filter((c) => DONE_STATUSES.includes(c.status)).length,
        overdue: inMonth.filter((c) => isOverdueOrLate(c, now, sla)).length,
      };
    }),
    byStatus: [...new Set(cases.map((c) => c.status))]
      .map((status) => ({ status, count: cases.filter((c) => c.status === status).length }))
      .sort((a, b) => b.count - a.count),
    byCaseType: types.map((caseType) => ({ caseType, count: cases.filter((c) => c.caseType === caseType).length })),
  };
}

export function productionReport({ cases }: Pick<ReportInput, 'cases'>): ProductionReport {
  const stages: [string, (c: LabCase) => number | null][] = [
    ['Received → Assigned', (c) => hoursBetween(c.receivedAt, c.assignedAt)],
    ['Assigned → Production start', (c) => hoursBetween(c.assignedAt, c.productionStartedAt)],
    ['Production', (c) => hoursBetween(c.productionStartedAt, c.productionCompletedAt)],
    ['Quality control', (c) => hoursBetween(c.productionCompletedAt, c.qcCompletedAt)],
    ['Ready → Delivered', (c) => hoursBetween(c.readyAt, c.deliveredAt)],
  ];
  return {
    stages: stages.map(([stage, fn]) => {
      const xs = cases.map(fn).filter((x): x is number => x !== null && x >= 0);
      return { stage, avgHours: average(xs), samples: xs.length };
    }),
  };
}

export function technicianReport(
  { cases }: Pick<ReportInput, 'cases'>,
  technicians: { id: string; name: string }[],
  qcFailuresByCase: Map<string, number>,
): TechnicianReport {
  return {
    technicians: technicians
      .map((t) => {
        const list = cases.filter((c) => c.technicianId === t.id);
        return {
          technicianId: t.id,
          name: t.name,
          assigned: list.length,
          completed: list.filter((c) => c.productionCompletedAt).length,
          onTimeRate: onTimeRate(list),
          avgProductionHours: average(list.map((c) => hoursBetween(c.productionStartedAt, c.productionCompletedAt)).filter((x): x is number => x !== null)),
          qcFailures: list.reduce((s, c) => s + (qcFailuresByCase.get(c.id) ?? 0), 0),
        };
      })
      .filter((t) => t.assigned > 0),
  };
}

export function clinicReport({ cases }: Pick<ReportInput, 'cases'>, clinics: { id: string; name: string }[]): ClinicReport {
  return {
    clinics: clinics
      .map((k) => {
        const list = cases.filter((c) => c.clinicId === k.id);
        return { clinicId: k.id, name: k.name, cases: list.length, completed: list.filter((c) => DONE_STATUSES.includes(c.status)).length };
      })
      .filter((k) => k.cases > 0)
      .sort((a, b) => b.cases - a.cases),
  };
}

export function financialReport(
  { filters, cases, dayOf }: Pick<ReportInput, 'filters' | 'cases' | 'dayOf'>,
  invoiceByCase: Map<string, Pick<Invoice, 'total' | 'paid' | 'remaining'>>,
): FinancialReport {
  const inv = (c: LabCase) => invoiceByCase.get(c.id);
  const sum = (list: LabCase[], k: 'total' | 'paid' | 'remaining') => round1(list.reduce((s, c) => s + (inv(c)?.[k] ?? 0), 0));
  const months = [...new Set(reportDays(filters).map((d) => d.slice(0, 7)))];
  const types = [...new Set(cases.map((c) => c.caseType))] as CaseType[];
  const clinicIds = [...new Set(cases.map((c) => c.clinicId))];
  return {
    totals: { revenue: sum(cases, 'total'), collected: sum(cases, 'paid'), outstanding: sum(cases, 'remaining') },
    monthly: months.map((m) => ({ label: monthLabel(m), revenue: sum(cases.filter((c) => dayOf(c.receivedAt ?? c.createdAt).slice(0, 7) === m), 'total') })),
    byCaseType: types.map((caseType) => ({ caseType, revenue: round1(cases.filter((c) => c.caseType === caseType).reduce((s, c) => s + c.total, 0)) })),
    clinics: clinicIds.map((clinicId) => {
      const list = cases.filter((c) => c.clinicId === clinicId);
      return { clinicId, revenue: sum(list, 'total'), outstanding: sum(list, 'remaining') };
    }),
  };
}

/** Merges the report sections into the single view the Reports page renders (money is 0 without the financial section). */
export function combineReport(parts: {
  cases: CaseReport;
  production: ProductionReport;
  technicians: TechnicianReport;
  clinics: ClinicReport;
  financial: FinancialReport | null;
}): ReportResult {
  const f = parts.financial;
  const monthRevenue = new Map(f?.monthly.map((m) => [m.label, m.revenue]) ?? []);
  const typeRevenue = new Map(f?.byCaseType.map((t) => [t.caseType, t.revenue]) ?? []);
  const clinicMoney = new Map(f?.clinics.map((k) => [k.clinicId, k]) ?? []);
  return {
    totals: { ...parts.cases.totals, revenue: f?.totals.revenue ?? 0, collected: f?.totals.collected ?? 0, outstanding: f?.totals.outstanding ?? 0 },
    daily: parts.cases.daily,
    monthly: parts.cases.monthly.map((m) => ({ ...m, revenue: monthRevenue.get(m.label) ?? 0 })),
    byStatus: parts.cases.byStatus,
    byCaseType: parts.cases.byCaseType.map((t) => ({ ...t, revenue: typeRevenue.get(t.caseType) ?? 0 })),
    technicians: parts.technicians.technicians,
    clinics: parts.clinics.clinics.map((k) => ({ ...k, revenue: clinicMoney.get(k.clinicId)?.revenue ?? 0, outstanding: clinicMoney.get(k.clinicId)?.outstanding ?? 0 })),
    stages: parts.production.stages,
  };
}

/* --------------------------- Directory statistics ----------------------- */

/** Totals shown on patient, doctor and clinic detail pages. */
export function relationStats(
  cases: (SlaFields & Pick<LabCase, 'id'>)[],
  invoiceByCase: Map<string, Pick<Invoice, 'total' | 'remaining'>>,
  now: number,
  sla: SlaConfig,
): RelationStats {
  let outstanding = 0;
  let billed = 0;
  for (const c of cases) {
    const inv = invoiceByCase.get(c.id);
    if (inv) {
      billed += inv.total;
      outstanding += inv.remaining;
    }
  }
  return {
    totalCases: cases.length,
    activeCases: cases.filter((c) => STATUS_META[c.status].open).length,
    completedCases: cases.filter((c) => DONE_STATUSES.includes(c.status)).length,
    overdueCases: cases.filter((c) => IN_LAB_STATUSES.includes(c.status) && getSlaInfo(c, now, sla).state === 'overdue').length,
    outstanding: round2(outstanding),
    billed: round2(billed),
  };
}

export type TechnicianWorkload = Pick<TechnicianListItem, 'activeCases' | 'completedCases' | 'dueToday' | 'overdue' | 'onTimeRate'>;

/** Workload figures shown in the technician list and profile. */
export function technicianWorkload(cases: SlaFields[], now: number, sla: SlaConfig, dayOf: DayOf): TechnicianWorkload {
  const today = dayOf(now);
  const active = cases.filter((c) => PRODUCTION_STATUSES.includes(c.status) || c.status === 'quality_control');
  const done = cases.filter((c) => DONE_STATUSES.includes(c.status));
  return {
    activeCases: active.length,
    completedCases: done.length + cases.filter((c) => c.status === 'ready' || c.status === 'out_for_delivery').length,
    dueToday: active.filter((c) => c.dueAt && dayOf(c.dueAt) === today && getSlaInfo(c, now, sla).state !== 'overdue').length,
    overdue: active.filter((c) => getSlaInfo(c, now, sla).state === 'overdue').length,
    onTimeRate: onTimeRate(done),
  };
}

/** Statuses a technician still works on (their "active case list"). */
export const TECHNICIAN_ACTIVE_STATUSES: CaseStatus[] = IN_LAB_STATUSES.filter((s) => s !== 'ready' && s !== 'out_for_delivery');
/** Statuses counted as finished work on a technician profile. */
export const TECHNICIAN_FINISHED_STATUSES: CaseStatus[] = [...DONE_STATUSES, 'ready', 'out_for_delivery'];
