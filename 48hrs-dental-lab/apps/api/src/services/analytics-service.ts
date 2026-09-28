/**
 * Dashboard, reports and global search. Rows are selected in PostgreSQL with
 * the caller's scope; the aggregation itself is the shared analytics module,
 * so the numbers match the mock backend exactly.
 */
import {
  buildDashboard,
  caseReport,
  clinicReport,
  dashboardWindowStart,
  financialReport,
  periodStartDay,
  productionReport,
  revenueWindowMonth,
  technicianReport,
  validateReportRange,
  type ReportInput,
} from '@48hrs/shared/analytics';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import type {
  CaseReport,
  ClinicReport,
  DashboardPeriod,
  DashboardSummary,
  FinancialReport,
  ProductionReport,
  ReportFilters,
  SearchResult,
  TechnicianReport,
} from '@48hrs/shared/types';
import { IN_LAB_STATUSES } from '@48hrs/shared/workflow';
import { Prisma } from '../generated/prisma/client.ts';
import { validation } from '../lib/errors.ts';
import { prisma } from '../lib/prisma.ts';
import { can, caseScope, clinicScope } from '../policies/case-policy.ts';
import { caseRepository, receivedBetween } from '../repositories/case-repository.ts';
import { invoiceFigures, toLabCase } from '../repositories/mappers.ts';
import type { AuthContext } from '../types/auth.ts';
import { iso, labDay, labDayStart } from '../utils/dates.ts';
import { money } from '../utils/money.ts';
import { caseSlaService } from './case-sla-service.ts';

const NOTHING: Prisma.DentalCaseWhereInput = { id: { in: [] } };
const slaSelect = { status: true, receivedAt: true, dueAt: true, deliveredAt: true, submittedAt: true, createdAt: true } satisfies Prisma.DentalCaseSelect;
const reportInclude = { invoice: { select: { id: true, total: true, amountPaid: true, dueDate: true } }, notes: { include: { author: { select: { name: true } } } } } satisfies Prisma.DentalCaseInclude;

async function totalOutstanding() {
  const [row] = await prisma.$queryRaw<{ outstanding: Prisma.Decimal | null }[]>`SELECT SUM(GREATEST(total - "amountPaid", 0)) AS outstanding FROM invoices`;
  return money(row?.outstanding);
}

export const analyticsService = {
  async dashboard(auth: AuthContext, period: DashboardPeriod): Promise<DashboardSummary> {
    const now = Date.now();
    const today = labDay(now);
    const scope = can(auth, PERMISSIONS.CASES_VIEW) ? caseScope(auth) : NOTHING;
    const since = labDayStart(dashboardWindowStart(period, today));
    const finance = can(auth, PERMISSIONS.REPORTS_FINANCIAL);
    const periodStart = periodStartDay(period, today);
    const revenueStart = `${revenueWindowMonth(today)}-01`;
    const moneySince = labDayStart(periodStart < revenueStart ? periodStart : revenueStart);

    const [statusCounts, inLab, recent, sla, invoices, payments, outstanding] = await Promise.all([
      caseRepository.statusCounts(prisma, scope),
      prisma.dentalCase.findMany({ where: { AND: [scope, { status: { in: IN_LAB_STATUSES } }] }, select: slaSelect }),
      prisma.dentalCase.findMany({
        where: { AND: [scope, { OR: [{ createdAt: { gte: since } }, { submittedAt: { gte: since } }, { receivedAt: { gte: since } }, { deliveredAt: { gte: since } }] }] },
        select: slaSelect,
      }),
      caseSlaService.config(prisma),
      finance ? prisma.invoice.findMany({ where: { issuedAt: { gte: moneySince } }, select: { issuedAt: true, total: true } }) : [],
      finance ? prisma.payment.findMany({ where: { paidAt: { gte: moneySince } }, select: { paidAt: true, amount: true } }) : [],
      finance ? totalOutstanding() : 0,
    ]);
    const plain = (c: Prisma.DentalCaseGetPayload<{ select: typeof slaSelect }>) => ({
      status: c.status,
      receivedAt: iso(c.receivedAt),
      dueAt: iso(c.dueAt),
      deliveredAt: iso(c.deliveredAt),
      submittedAt: iso(c.submittedAt),
      createdAt: c.createdAt.toISOString(),
    });
    return buildDashboard({
      now,
      dayOf: labDay,
      sla,
      period,
      statusCounts,
      inLab: inLab.map(plain),
      recent: recent.map(plain),
      finance: finance
        ? {
            invoices: invoices.map((i) => ({ issuedAt: i.issuedAt.toISOString(), total: money(i.total) })),
            payments: payments.map((p) => ({ paidAt: p.paidAt.toISOString(), amount: money(p.amount) })),
            outstanding,
          }
        : null,
    });
  },

  /** Visible, non-rejected cases in the report's date range and filters, as shared LabCase rows. */
  async reportInput(auth: AuthContext, filters: ReportFilters): Promise<{ input: ReportInput; rows: Prisma.DentalCaseGetPayload<{ include: typeof reportInclude }>[] }> {
    const rangeError = validateReportRange(filters.from, filters.to);
    if (rangeError) throw validation({ from: [rangeError] }, rangeError);
    const now = Date.now();
    const where: Prisma.DentalCaseWhereInput = {
      AND: [
        caseScope(auth),
        { status: { not: 'rejected' } },
        receivedBetween(filters.from, filters.to)!,
        filters.technicianId ? { technicianId: filters.technicianId } : {},
        filters.doctorId ? { doctorId: filters.doctorId } : {},
        filters.clinicId ? { clinicId: filters.clinicId } : {},
        filters.status ? { status: filters.status } : {},
        filters.caseType ? { caseType: filters.caseType } : {},
      ],
    };
    const [rows, sla] = await Promise.all([prisma.dentalCase.findMany({ where, include: reportInclude }), caseSlaService.config(prisma)]);
    return { rows, input: { filters, cases: rows.map((r) => toLabCase(r, now)), now, sla, dayOf: labDay } };
  },

  async caseReport(auth: AuthContext, f: ReportFilters): Promise<CaseReport> {
    return caseReport((await this.reportInput(auth, f)).input);
  },

  async productionReport(auth: AuthContext, f: ReportFilters): Promise<ProductionReport> {
    return productionReport((await this.reportInput(auth, f)).input);
  },

  async technicianReport(auth: AuthContext, f: ReportFilters): Promise<TechnicianReport> {
    const { input } = await this.reportInput(auth, f);
    const [technicians, failures] = await Promise.all([
      prisma.technician.findMany({ select: { id: true, name: true }, orderBy: { name: 'asc' } }),
      prisma.qualityCheck.groupBy({ by: ['caseId'], where: { result: 'failed', caseId: { in: input.cases.map((c) => c.id) } }, _count: { _all: true } }),
    ]);
    return technicianReport(input, technicians, new Map(failures.map((x) => [x.caseId, x._count._all])));
  },

  async clinicReport(auth: AuthContext, f: ReportFilters): Promise<ClinicReport> {
    const { input } = await this.reportInput(auth, f);
    return clinicReport(input, await prisma.clinic.findMany({ select: { id: true, name: true }, orderBy: { name: 'asc' } }));
  },

  async financialReport(auth: AuthContext, f: ReportFilters): Promise<FinancialReport> {
    const { input, rows } = await this.reportInput(auth, f);
    const invoices = new Map(rows.filter((r) => r.invoice).map((r) => [r.id, invoiceFigures(r.invoice!, input.now)] as const));
    return financialReport(input, invoices);
  },

  /** Cases, patients, doctors, clinics and invoices the caller may open; phone numbers match on digits. */
  async search(auth: AuthContext, raw: string): Promise<SearchResult[]> {
    const term = raw.trim();
    if (term.length < 2) return [];
    const digits = term.replace(/\D/g, '');
    const byDigits = digits.length >= 4;
    const like = { contains: term, mode: 'insensitive' as const };
    const scopeClinic = clinicScope(auth);
    const out: SearchResult[] = [];

    const phoneIds = async (table: 'patients' | 'doctors' | 'clinics') => {
      if (!byDigits) return [];
      const rows = await prisma.$queryRaw<{ id: string }[]>(
        Prisma.sql`SELECT id FROM ${Prisma.raw(`"${table}"`)} WHERE regexp_replace(phone, '\\D', '', 'g') LIKE ${`%${digits}%`} LIMIT 25`,
      );
      return rows.map((r) => r.id);
    };

    if (can(auth, PERMISSIONS.CASES_VIEW)) {
      const patientIds = await phoneIds('patients');
      const cases = await prisma.dentalCase.findMany({
        where: { AND: [caseScope(auth), { OR: [{ caseNumber: like }, { patient: { name: like } }, { patient: { code: like } }, { patientId: { in: patientIds } }] }] },
        orderBy: { createdAt: 'desc' },
        take: 6,
        select: { id: true, caseNumber: true, restorationType: true, patient: { select: { name: true } }, clinic: { select: { name: true } } },
      });
      cases.forEach((c) => out.push({ type: 'case', id: c.id, title: c.caseNumber, subtitle: `${c.patient.name} · ${c.restorationType} · ${c.clinic.name}`, href: `/cases/${c.id}` }));
    }
    if (can(auth, PERMISSIONS.PATIENTS_VIEW)) {
      const ids = await phoneIds('patients');
      const patients = await prisma.patient.findMany({
        where: { AND: [scopeClinic === null ? {} : { clinicId: scopeClinic || '__none__' }, { OR: [{ name: like }, { code: like }, { email: like }, { id: { in: ids } }] }] },
        orderBy: { name: 'asc' },
        take: 5,
      });
      patients.forEach((p) => out.push({ type: 'patient', id: p.id, title: p.name, subtitle: `${p.code}${p.phone ? ` · ${p.phone}` : ''}`, href: `/patients/${p.id}` }));
    }
    if (can(auth, PERMISSIONS.DOCTORS_VIEW)) {
      const ids = await phoneIds('doctors');
      const doctors = await prisma.doctor.findMany({ where: { OR: [{ name: like }, { email: like }, { id: { in: ids } }] }, orderBy: { name: 'asc' }, take: 5, include: { clinic: { select: { name: true } } } });
      doctors.forEach((d) => out.push({ type: 'doctor', id: d.id, title: d.name, subtitle: `${d.clinic.name} · ${d.phone}`, href: `/doctors/${d.id}` }));
    }
    if (can(auth, PERMISSIONS.CLINICS_VIEW)) {
      const ids = await phoneIds('clinics');
      const clinics = await prisma.clinic.findMany({ where: { OR: [{ name: like }, { email: like }, { contactPerson: like }, { id: { in: ids } }] }, orderBy: { name: 'asc' }, take: 5 });
      clinics.forEach((k) => out.push({ type: 'clinic', id: k.id, title: k.name, subtitle: `${k.contactPerson} · ${k.phone}`, href: `/clinics/${k.id}` }));
    }
    if (can(auth, PERMISSIONS.INVOICES_VIEW)) {
      const invoices = await prisma.invoice.findMany({
        where: { AND: [scopeClinic === null ? {} : { clinicId: scopeClinic || '__none__' }, { invoiceNumber: like }] },
        orderBy: { issuedAt: 'desc' },
        take: 5,
        include: { case: { select: { caseNumber: true } }, clinic: { select: { name: true } } },
      });
      invoices.forEach((i) => out.push({ type: 'invoice', id: i.id, title: i.invoiceNumber, subtitle: `${i.case.caseNumber} · ${i.clinic.name}`, href: `/invoices/${i.id}` }));
    }
    return out;
  },
};
