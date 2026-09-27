import { PERMISSIONS, hasPermission } from '@/lib/permissions';
import { getSlaInfo, HOUR_MS } from '@/lib/sla';
import { DONE_STATUSES, IN_LAB_STATUSES, OPEN_STATUSES, PRODUCTION_STATUSES } from '@/lib/workflow';
import { ApiError } from '@/services/api/errors';
import type { DashboardSummary, ReportResult, SearchResult } from '@/types/api';
import type { CaseStatus, CaseType, LabCase } from '@/types/models';
import { localDay } from '@/utils/dates';
import { authenticate, authorize } from '../auth-context';
import type { MockDatabase } from '../db';
import { invoiceView, slaConfig, visibleCases } from '../domain';
import { qStr, route } from '../router';

const DAY_MS = 24 * HOUR_MS;
const round1 = (n: number) => Math.round(n * 10) / 10;
const avg = (xs: number[]) => (xs.length ? round1(xs.reduce((s, x) => s + x, 0) / xs.length) : null);
const monthKey = (v: string | number) => localDay(v).slice(0, 7);
const monthLabel = (key: string) => new Date(`${key}-01T12:00:00`).toLocaleDateString('en-GB', { month: 'short', year: '2-digit' });

function hoursBetween(a?: string | null, b?: string | null) {
  return a && b ? (new Date(b).getTime() - new Date(a).getTime()) / HOUR_MS : null;
}

function invoiceOf(db: MockDatabase, c: LabCase, now: number) {
  const inv = c.invoiceId ? db.invoices.find((i) => i.id === c.invoiceId) : undefined;
  return inv ? invoiceView(db, inv, now) : null;
}

/* ------------------------------- Dashboard ------------------------------ */

route('GET', '/dashboard', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.DASHBOARD_VIEW);
  const { db, now } = ctx;
  const cfg = slaConfig(db);
  const cases = hasPermission(ctx.permissions, PERMISSIONS.CASES_VIEW) ? visibleCases(ctx) : [];
  const today = localDay(now);
  const inLab = cases.filter((c) => IN_LAB_STATUSES.includes(c.status));
  const sla = inLab.map((c) => ({ c, info: getSlaInfo(c, now, cfg) }));
  const n = (...s: CaseStatus[]) => cases.filter((c) => s.includes(c.status)).length;
  const recentDone = cases.filter((c) => c.deliveredAt && c.receivedAt && now - new Date(c.deliveredAt).getTime() <= 30 * DAY_MS);
  const finance = hasPermission(ctx.permissions, PERMISSIONS.REPORTS_FINANCIAL);

  const last14Days = Array.from({ length: 14 }, (_, i) => {
    const day = localDay(now - (13 - i) * DAY_MS);
    return {
      date: day,
      received: cases.filter((c) => c.receivedAt && localDay(c.receivedAt) === day).length,
      delivered: cases.filter((c) => c.deliveredAt && localDay(c.deliveredAt) === day).length,
    };
  });

  let revenueByMonth: DashboardSummary['revenueByMonth'] = null;
  let revenueMonth: number | null = null;
  let outstanding: number | null = null;
  if (finance) {
    const months = Array.from({ length: 6 }, (_, i) => {
      const d = new Date(now);
      d.setDate(1);
      d.setMonth(d.getMonth() - (5 - i));
      return monthKey(d.getTime());
    });
    revenueByMonth = months.map((m) => ({
      month: monthLabel(m),
      invoiced: round1(db.invoices.filter((i) => monthKey(i.issuedAt) === m).reduce((s, i) => s + i.total, 0)),
      collected: round1(db.payments.filter((p) => monthKey(p.paidAt) === m).reduce((s, p) => s + p.amount, 0)),
    }));
    revenueMonth = revenueByMonth[5].invoiced;
    outstanding = round1(db.invoices.reduce((s, i) => s + invoiceView(db, i, now).remaining, 0));
  }

  const summary: DashboardSummary = {
    activeCases: inLab.length,
    newToday: cases.filter((c) => localDay(c.receivedAt ?? c.submittedAt ?? c.createdAt) === today && c.status !== 'rejected').length,
    dueToday: sla.filter(({ c, info }) => c.dueAt && localDay(c.dueAt) === today && info.state !== 'overdue').length,
    overdue: sla.filter(({ info }) => info.state === 'overdue').length,
    completed: n(...DONE_STATUSES),
    completedToday: cases.filter((c) => c.deliveredAt && localDay(c.deliveredAt) === today).length,
    inProduction: n(...PRODUCTION_STATUSES),
    pendingQc: n('quality_control'),
    readyForDelivery: n('ready', 'out_for_delivery'),
    awaitingAcceptance: n('submitted'),
    revenueMonth,
    outstanding,
    performance: {
      onTime: sla.filter(({ info }) => info.state === 'on_track').length,
      atRisk: sla.filter(({ info }) => info.state === 'at_risk' || info.state === 'critical').length,
      overdue: sla.filter(({ info }) => info.state === 'overdue').length,
      onTimeRate: recentDone.length ? recentDone.filter((c) => c.deliveredAt! <= c.dueAt!).length / recentDone.length : null,
      avgCompletionHours: avg(recentDone.map((c) => hoursBetween(c.receivedAt, c.deliveredAt)!)),
    },
    last14Days,
    statusBreakdown: OPEN_STATUSES.map((status) => ({ status, count: n(status) })).filter((s) => s.count > 0),
    revenueByMonth,
  };
  return summary;
});

/* -------------------------------- Reports ------------------------------- */

route('GET', '/reports', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.REPORTS_VIEW);
  const { db, now, query: q } = ctx;
  const from = qStr(q, 'from');
  const to = qStr(q, 'to');
  if (!from || !to) throw new ApiError(422, 'Choose a date range.');
  if (from > to) throw new ApiError(422, 'The start date must be before the end date.');
  const finance = hasPermission(ctx.permissions, PERMISSIONS.REPORTS_FINANCIAL);

  const cases = visibleCases(ctx)
    .filter((c) => c.status !== 'rejected')
    .filter((c) => {
      const d = localDay(c.receivedAt ?? c.createdAt);
      return d >= from && d <= to;
    })
    .filter((c) => !qStr(q, 'technicianId') || c.technicianId === qStr(q, 'technicianId'))
    .filter((c) => !qStr(q, 'doctorId') || c.doctorId === qStr(q, 'doctorId'))
    .filter((c) => !qStr(q, 'clinicId') || c.clinicId === qStr(q, 'clinicId'))
    .filter((c) => !qStr(q, 'status') || c.status === qStr(q, 'status'))
    .filter((c) => !qStr(q, 'caseType') || c.caseType === qStr(q, 'caseType'));

  const cfg = slaConfig(db);
  const isOverdue = (c: LabCase) => {
    const s = getSlaInfo(c, now, cfg).state;
    return s === 'overdue' || s === 'late';
  };
  const delivered = cases.filter((c) => c.deliveredAt && c.dueAt);
  const onTimeRate = (list: LabCase[]) => {
    const d = list.filter((c) => c.deliveredAt && c.dueAt);
    return d.length ? d.filter((c) => c.deliveredAt! <= c.dueAt!).length / d.length : null;
  };
  const invs = cases.map((c) => invoiceOf(db, c, now)).filter((x): x is NonNullable<typeof x> => !!x);
  const money = (n: number) => (finance ? round1(n) : 0);

  // Daily series across the range (capped at 120 points).
  const days: string[] = [];
  for (let t = new Date(`${from}T12:00:00`).getTime(); localDay(t) <= to && days.length < 120; t += DAY_MS) days.push(localDay(t));
  const daily = days.map((day) => ({
    label: day,
    received: cases.filter((c) => localDay(c.receivedAt ?? c.createdAt) === day).length,
    completed: cases.filter((c) => c.deliveredAt && localDay(c.deliveredAt) === day).length,
    overdue: cases.filter((c) => c.dueAt && localDay(c.dueAt) === day && isOverdue(c)).length,
  }));

  const months = [...new Set(days.map((d) => d.slice(0, 7)))];
  const monthly = months.map((m) => {
    const inMonth = cases.filter((c) => monthKey(c.receivedAt ?? c.createdAt) === m);
    return {
      label: monthLabel(m),
      received: inMonth.length,
      completed: inMonth.filter((c) => DONE_STATUSES.includes(c.status)).length,
      overdue: inMonth.filter(isOverdue).length,
      revenue: money(inMonth.reduce((s, c) => s + (invoiceOf(db, c, now)?.total ?? 0), 0)),
    };
  });

  const statuses = [...new Set(cases.map((c) => c.status))];
  const types = [...new Set(cases.map((c) => c.caseType))] as CaseType[];

  const result: ReportResult = {
    totals: {
      cases: cases.length,
      completed: cases.filter((c) => DONE_STATUSES.includes(c.status)).length,
      open: cases.filter((c) => OPEN_STATUSES.includes(c.status)).length,
      overdue: cases.filter(isOverdue).length,
      onTimeRate: onTimeRate(cases),
      avgTurnaroundHours: avg(delivered.map((c) => hoursBetween(c.receivedAt, c.deliveredAt)!)),
      revenue: money(invs.reduce((s, i) => s + i.total, 0)),
      collected: money(invs.reduce((s, i) => s + i.paid, 0)),
      outstanding: money(invs.reduce((s, i) => s + i.remaining, 0)),
    },
    daily,
    monthly,
    byStatus: statuses.map((status) => ({ status, count: cases.filter((c) => c.status === status).length })).sort((a, b) => b.count - a.count),
    byCaseType: types.map((caseType) => {
      const list = cases.filter((c) => c.caseType === caseType);
      return { caseType, count: list.length, revenue: money(list.reduce((s, c) => s + c.total, 0)) };
    }),
    technicians: db.technicians
      .map((t) => {
        const list = cases.filter((c) => c.technicianId === t.id);
        return {
          technicianId: t.id,
          name: t.name,
          assigned: list.length,
          completed: list.filter((c) => c.productionCompletedAt).length,
          onTimeRate: onTimeRate(list),
          avgProductionHours: avg(list.map((c) => hoursBetween(c.productionStartedAt, c.productionCompletedAt)).filter((x): x is number => x !== null)),
          qcFailures: db.qualityChecks.filter((qc) => qc.result === 'failed' && list.some((c) => c.id === qc.caseId)).length,
        };
      })
      .filter((t) => t.assigned > 0),
    clinics: db.clinics
      .map((k) => {
        const list = cases.filter((c) => c.clinicId === k.id);
        const ci = list.map((c) => invoiceOf(db, c, now)).filter((x): x is NonNullable<typeof x> => !!x);
        return {
          clinicId: k.id,
          name: k.name,
          cases: list.length,
          completed: list.filter((c) => DONE_STATUSES.includes(c.status)).length,
          revenue: money(ci.reduce((s, i) => s + i.total, 0)),
          outstanding: money(ci.reduce((s, i) => s + i.remaining, 0)),
        };
      })
      .filter((k) => k.cases > 0)
      .sort((a, b) => b.cases - a.cases),
    stages: [
      { stage: 'Received → Assigned', pairs: cases.map((c) => hoursBetween(c.receivedAt, c.assignedAt)) },
      { stage: 'Assigned → Production start', pairs: cases.map((c) => hoursBetween(c.assignedAt, c.productionStartedAt)) },
      { stage: 'Production', pairs: cases.map((c) => hoursBetween(c.productionStartedAt, c.productionCompletedAt)) },
      { stage: 'Quality control', pairs: cases.map((c) => hoursBetween(c.productionCompletedAt, c.qcCompletedAt)) },
      { stage: 'Ready → Delivered', pairs: cases.map((c) => hoursBetween(c.readyAt, c.deliveredAt)) },
    ].map(({ stage, pairs }) => {
      const xs = pairs.filter((x): x is number => x !== null && x >= 0);
      return { stage, avgHours: avg(xs), samples: xs.length };
    }),
  };
  return result;
});

/* -------------------------------- Search -------------------------------- */

route('GET', '/search', (raw) => {
  const ctx = authenticate(raw);
  const term = (qStr(raw.query, 'q') ?? '').trim().toLowerCase();
  if (term.length < 2) return [];
  const { db } = ctx;
  const has = (p: string) => hasPermission(ctx.permissions, p);
  const hit = (...vals: (string | null | undefined)[]) => vals.some((v) => v?.toLowerCase().includes(term));
  const digits = term.replace(/\D/g, '');
  const phoneHit = (phone?: string) => digits.length >= 4 && !!phone && phone.replace(/\D/g, '').includes(digits);
  const out: SearchResult[] = [];
  const scopeClinic = has(PERMISSIONS.CASES_VIEW_ALL) ? null : ctx.user.clinicId;

  if (has(PERMISSIONS.CASES_VIEW)) {
    visibleCases(ctx)
      .filter((c) => {
        const p = db.patients.find((x) => x.id === c.patientId);
        return hit(c.caseNumber, p?.name, p?.code) || phoneHit(p?.phone);
      })
      .slice(0, 6)
      .forEach((c) => {
        const p = db.patients.find((x) => x.id === c.patientId);
        const k = db.clinics.find((x) => x.id === c.clinicId);
        out.push({ type: 'case', id: c.id, title: c.caseNumber, subtitle: `${p?.name ?? ''} · ${c.restorationType} · ${k?.name ?? ''}`, href: `/cases/${c.id}` });
      });
  }
  if (has(PERMISSIONS.PATIENTS_VIEW)) {
    db.patients
      .filter((p) => (!scopeClinic || p.clinicId === scopeClinic) && (hit(p.name, p.code, p.email) || phoneHit(p.phone)))
      .slice(0, 5)
      .forEach((p) => out.push({ type: 'patient', id: p.id, title: p.name, subtitle: `${p.code}${p.phone ? ` · ${p.phone}` : ''}`, href: `/patients/${p.id}` }));
  }
  if (has(PERMISSIONS.DOCTORS_VIEW)) {
    db.doctors
      .filter((d) => hit(d.name, d.email) || phoneHit(d.phone))
      .slice(0, 5)
      .forEach((d) => out.push({ type: 'doctor', id: d.id, title: d.name, subtitle: `${db.clinics.find((k) => k.id === d.clinicId)?.name ?? ''} · ${d.phone}`, href: `/doctors/${d.id}` }));
  }
  if (has(PERMISSIONS.CLINICS_VIEW)) {
    db.clinics
      .filter((k) => hit(k.name, k.email, k.contactPerson) || phoneHit(k.phone))
      .slice(0, 5)
      .forEach((k) => out.push({ type: 'clinic', id: k.id, title: k.name, subtitle: `${k.contactPerson} · ${k.phone}`, href: `/clinics/${k.id}` }));
  }
  if (has(PERMISSIONS.INVOICES_VIEW)) {
    db.invoices
      .filter((i) => (!scopeClinic || i.clinicId === scopeClinic) && hit(i.invoiceNumber))
      .slice(0, 5)
      .forEach((i) => out.push({ type: 'invoice', id: i.id, title: i.invoiceNumber, subtitle: `${db.cases.find((c) => c.id === i.caseId)?.caseNumber ?? ''} · ${db.clinics.find((k) => k.id === i.clinicId)?.name ?? ''}`, href: `/invoices/${i.id}` }));
  }
  return out;
});
