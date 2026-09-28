import {
  buildDashboard,
  caseReport,
  clinicReport,
  dashboardWindowStart,
  financialReport,
  matchesReportFilters,
  parsePeriod,
  productionReport,
  technicianReport,
  validateReportRange,
  type ReportInput,
} from '@48hrs/shared/analytics';
import { localDay } from '@48hrs/shared/dates';
import { PERMISSIONS, hasPermission } from '@48hrs/shared/permissions';
import type { CaseStatus, CaseType, Invoice, ReportFilters, SearchResult } from '@48hrs/shared/types';
import { IN_LAB_STATUSES } from '@48hrs/shared/workflow';
import { authenticate, authorize } from '../auth-context';
import { invoiceView, slaConfig, visibleCases } from '../domain';
import { qStr, route, validationError, type AuthedContext } from '../router';

/* ------------------------------- Dashboard ------------------------------ */

route('GET', '/dashboard', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.DASHBOARD_VIEW);
  const { db, now } = ctx;
  const cases = hasPermission(ctx.permissions, PERMISSIONS.CASES_VIEW) ? visibleCases(ctx) : [];
  const period = parsePeriod(qStr(raw.query, 'period'));
  const windowStart = dashboardWindowStart(period, localDay(now));
  const statusCounts: Partial<Record<CaseStatus, number>> = {};
  cases.forEach((c) => (statusCounts[c.status] = (statusCounts[c.status] ?? 0) + 1));
  const invoices = db.invoices.map((i) => invoiceView(db, i, now));
  return buildDashboard({
    now,
    dayOf: localDay,
    sla: slaConfig(db),
    period,
    statusCounts,
    inLab: cases.filter((c) => IN_LAB_STATUSES.includes(c.status)),
    recent: cases.filter((c) => [c.createdAt, c.submittedAt, c.receivedAt, c.deliveredAt].some((v) => v && localDay(v) >= windowStart)),
    finance: hasPermission(ctx.permissions, PERMISSIONS.REPORTS_FINANCIAL)
      ? { invoices, payments: db.payments, outstanding: invoices.reduce((s, i) => s + i.remaining, 0) }
      : null,
  });
});

/* -------------------------------- Reports ------------------------------- */

function reportInput(raw: Parameters<typeof authenticate>[0], permission: string = PERMISSIONS.REPORTS_VIEW): { ctx: AuthedContext; input: ReportInput } {
  const ctx = authenticate(raw);
  authorize(ctx, [PERMISSIONS.REPORTS_VIEW, permission]);
  const q = raw.query;
  const filters: ReportFilters = {
    from: qStr(q, 'from') ?? '',
    to: qStr(q, 'to') ?? '',
    technicianId: qStr(q, 'technicianId'),
    doctorId: qStr(q, 'doctorId'),
    clinicId: qStr(q, 'clinicId'),
    status: qStr(q, 'status') as CaseStatus | undefined,
    caseType: qStr(q, 'caseType') as CaseType | undefined,
  };
  const rangeError = validateReportRange(filters.from, filters.to);
  if (rangeError) throw validationError({ from: [rangeError] }, rangeError);
  const cases = visibleCases(ctx).filter((c) => matchesReportFilters(c, filters, localDay));
  return { ctx, input: { filters, cases, now: ctx.now, sla: slaConfig(ctx.db), dayOf: localDay } };
}

route('GET', '/reports/cases', (raw) => caseReport(reportInput(raw).input));

route('GET', '/reports/production', (raw) => productionReport(reportInput(raw).input));

route('GET', '/reports/technicians', (raw) => {
  const { ctx, input } = reportInput(raw);
  const failures = new Map<string, number>();
  ctx.db.qualityChecks.filter((q) => q.result === 'failed').forEach((q) => failures.set(q.caseId, (failures.get(q.caseId) ?? 0) + 1));
  return technicianReport(input, ctx.db.technicians, failures);
});

route('GET', '/reports/clinics', (raw) => {
  const { ctx, input } = reportInput(raw);
  return clinicReport(input, ctx.db.clinics);
});

route('GET', '/reports/financial', (raw) => {
  const { ctx, input } = reportInput(raw, PERMISSIONS.REPORTS_FINANCIAL);
  const invoices = new Map<string, Invoice>();
  input.cases.forEach((c) => {
    const inv = c.invoiceId ? ctx.db.invoices.find((i) => i.id === c.invoiceId) : undefined;
    if (inv) invoices.set(c.id, invoiceView(ctx.db, inv, ctx.now));
  });
  return financialReport(input, invoices);
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
