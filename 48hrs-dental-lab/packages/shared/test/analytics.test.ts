import { describe, expect, it } from 'vitest';
import { buildDashboard, caseReport, combineReport, financialReport, matchesReportFilters, periodStartDay, relationStats, validateReportRange } from '../src/analytics';
import { buildDemoDataset } from '../src/demo-data';
import { dayFnFor } from '../src/dates';
import { invoiceTotals } from '../src/billing';
import { DEFAULT_SLA_CONFIG } from '../src/sla';
import { IN_LAB_STATUSES } from '../src/workflow';
import type { CaseStatus, Invoice } from '../src/types';

const now = Date.UTC(2026, 8, 28, 9, 0);
const data = buildDemoDataset(now);
const dayOf = dayFnFor('UTC');

describe('dashboard', () => {
  it('period starts and counts from the rows it is given', () => {
    expect(periodStartDay('today', '2026-09-28')).toBe('2026-09-28');
    expect(periodStartDay('7d', '2026-09-28')).toBe('2026-09-22');
    expect(periodStartDay('month', '2026-09-28')).toBe('2026-09-01');
    const statusCounts: Partial<Record<CaseStatus, number>> = {};
    data.cases.forEach((c) => (statusCounts[c.status] = (statusCounts[c.status] ?? 0) + 1));
    const d = buildDashboard({ now, dayOf, sla: DEFAULT_SLA_CONFIG, period: '30d', statusCounts, inLab: data.cases.filter((c) => IN_LAB_STATUSES.includes(c.status)), recent: data.cases, finance: null });
    expect(d.activeCases).toBe(data.cases.filter((c) => IN_LAB_STATUSES.includes(c.status)).length);
    expect(d.overdue).toBeGreaterThan(0);
    expect(d.performance.onTime + d.performance.atRisk + d.performance.overdue).toBe(d.activeCases);
    expect([d.revenue, d.collected, d.outstanding, d.revenueByMonth]).toEqual([null, null, null, null]);
    expect(d.last14Days).toHaveLength(14);
  });
});

describe('reports', () => {
  const filters = { from: '2026-07-01', to: '2026-09-28' };
  const cases = data.cases.filter((c) => matchesReportFilters(c, filters, dayOf));
  const input = { filters, cases, now, sla: DEFAULT_SLA_CONFIG, dayOf };

  it('validates the range', () => {
    expect(validateReportRange('2026-09-01', '2026-08-01')).toMatch(/before/);
    expect(validateReportRange('', '2026-08-01')).toMatch(/range/);
    expect(validateReportRange('2026-08-01', '2026-09-01')).toBeNull();
  });

  it('combines the sections; money appears only with the financial section', () => {
    const invoices = new Map(
      cases.flatMap((c) => {
        const inv = data.invoices.find((i) => i.caseId === c.id);
        return inv ? [[c.id, { ...inv, ...invoiceTotals(inv.total, data.payments.filter((p) => p.invoiceId === inv.id), inv.dueDate, now) } as Invoice] as const] : [];
      }),
    );
    const parts = { cases: caseReport(input), production: { stages: [] }, technicians: { technicians: [] }, clinics: { clinics: [] } };
    const without = combineReport({ ...parts, financial: null });
    expect(without.totals.revenue).toBe(0);
    const money = financialReport(input, invoices);
    const withMoney = combineReport({ ...parts, financial: money });
    expect(withMoney.totals.revenue).toBeGreaterThan(0);
    expect(withMoney.totals.revenue).toBeCloseTo(withMoney.totals.collected + withMoney.totals.outstanding, 0);
    expect(withMoney.totals.cases).toBe(cases.length);
  });

  it('relation stats: billed = sum of totals, outstanding = sum of remaining', () => {
    const invoices = new Map([[data.cases[0].id, { total: 100, remaining: 40 }], [data.cases[1].id, { total: 50, remaining: 0 }]]);
    const s = relationStats(data.cases.slice(0, 2), invoices, now, DEFAULT_SLA_CONFIG);
    expect(s).toMatchObject({ totalCases: 2, billed: 150, outstanding: 40 });
  });
});
