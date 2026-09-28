/**
 * Case queries. Every filter runs in PostgreSQL (indexes on status, priority,
 * due_at, clinic, doctor, technician, patient, created_at), so lists stay fast
 * however many cases the lab accumulates.
 */
import type { CaseListParams, CaseStatus, PaymentStatus, SlaFilter } from '@48hrs/shared/types';
import type { SlaConfig } from '@48hrs/shared/sla';
import { OPEN_STATUSES } from '@48hrs/shared/workflow';
import type { Prisma } from '../generated/prisma/client.ts';
import { prisma, type DbOrTx } from '../lib/prisma.ts';
import { caseSlaService } from '../services/case-sla-service.ts';
import { labDayEnd, labDayStart } from '../utils/dates.ts';
import { caseDetailInclude, caseListInclude } from './mappers.ts';

export type CaseFilters = Omit<CaseListParams, 'page' | 'perPage' | 'sort' | 'dir'>;

const insensitive = (v: string) => ({ contains: v, mode: 'insensitive' as const });

/** Invoice-status filter expressed on invoices.amount_paid / total / due_date. */
function paymentWhere(status: PaymentStatus, now: Date): Prisma.DentalCaseWhereInput {
  const total = prisma.invoice.fields.total;
  switch (status) {
    case 'paid':
      return { invoice: { is: { amountPaid: { gte: total } } } };
    case 'overdue':
      return { invoice: { is: { amountPaid: { lt: total }, dueDate: { lt: now } } } };
    case 'partial':
      return { invoice: { is: { amountPaid: { gt: 0, lt: total }, dueDate: { gte: now } } } };
    case 'unpaid':
      return { OR: [{ invoice: { is: null } }, { invoice: { is: { amountPaid: 0, total: { gt: 0 }, dueDate: { gte: now } } } }] };
  }
}

export function caseSearchWhere(search: string): Prisma.DentalCaseWhereInput {
  return {
    OR: [
      { caseNumber: insensitive(search) },
      { restorationType: insensitive(search) },
      { patient: { name: insensitive(search) } },
      { patient: { code: insensitive(search) } },
      { doctor: { name: insensitive(search) } },
      { clinic: { name: insensitive(search) } },
      { technician: { name: insensitive(search) } },
    ],
  };
}

/** Day range on "received" (falls back to created for cases not yet received). */
export function receivedBetween(from?: string, to?: string): Prisma.DentalCaseWhereInput | null {
  if (!from && !to) return null;
  const range = { ...(from ? { gte: labDayStart(from) } : {}), ...(to ? { lt: labDayEnd(to) } : {}) };
  return { OR: [{ receivedAt: range }, { receivedAt: null, createdAt: range }] };
}

export function caseListWhere(scope: Prisma.DentalCaseWhereInput, f: CaseFilters, sla: SlaConfig, now = Date.now()): Prisma.DentalCaseWhereInput {
  const and: Prisma.DentalCaseWhereInput[] = [scope];
  if (f.status?.length) and.push({ status: { in: f.status } });
  if (f.priority?.length) and.push({ priority: { in: f.priority } });
  if (f.technicianId) and.push({ technicianId: f.technicianId });
  if (f.doctorId) and.push({ doctorId: f.doctorId });
  if (f.clinicId) and.push({ clinicId: f.clinicId });
  if (f.patientId) and.push({ patientId: f.patientId });
  if (f.caseType) and.push({ caseType: f.caseType });
  if (f.openOnly) and.push({ status: { in: OPEN_STATUSES } });
  if (f.dueFrom || f.dueTo) and.push({ dueAt: { ...(f.dueFrom ? { gte: labDayStart(f.dueFrom) } : {}), ...(f.dueTo ? { lt: labDayEnd(f.dueTo) } : {}) } });
  const received = receivedBetween(f.from, f.to);
  if (received) and.push(received);
  if (f.sla) and.push(caseSlaService.filterWhere(f.sla as SlaFilter, sla, now));
  if (f.paymentStatus) and.push(paymentWhere(f.paymentStatus, new Date(now)));
  if (f.search) and.push(caseSearchWhere(f.search));
  return { AND: and };
}

export const CASE_SORTS = ['caseNumber', 'patient', 'doctor', 'clinic', 'caseType', 'priority', 'receivedAt', 'dueAt', 'status', 'technician', 'total', 'paymentStatus', 'createdAt'] as const;
export type CaseSort = (typeof CASE_SORTS)[number];

export function caseOrderBy(sort: CaseSort, dir: 'asc' | 'desc'): Prisma.DentalCaseOrderByWithRelationInput[] {
  const nullsLast = { sort: dir, nulls: 'last' as const };
  const tie: Prisma.DentalCaseOrderByWithRelationInput = { caseNumber: dir };
  switch (sort) {
    case 'caseNumber':
      return [{ caseNumber: dir }];
    case 'patient':
      return [{ patient: { name: dir } }, tie];
    case 'doctor':
      return [{ doctor: { name: dir } }, tie];
    case 'clinic':
      return [{ clinic: { name: dir } }, tie];
    case 'caseType':
      return [{ restorationType: dir }, tie];
    case 'priority':
      return [{ priority: dir }, tie];
    case 'receivedAt':
      return [{ receivedAt: nullsLast }, { createdAt: dir }];
    case 'dueAt':
      return [{ dueAt: nullsLast }, tie];
    case 'status':
      return [{ status: dir }, tie];
    case 'technician':
      return [{ technician: { name: dir } }, tie];
    case 'total':
      return [{ total: dir }, tie];
    case 'paymentStatus':
      return [{ invoice: { amountPaid: dir } }, tie];
    case 'createdAt':
      return [{ createdAt: dir }];
  }
}

export const caseRepository = {
  count(db: DbOrTx, where: Prisma.DentalCaseWhereInput) {
    return db.dentalCase.count({ where });
  },

  list(db: DbOrTx, where: Prisma.DentalCaseWhereInput, orderBy: Prisma.DentalCaseOrderByWithRelationInput[], skip: number, take: number) {
    return db.dentalCase.findMany({ where, orderBy, skip, take, include: caseListInclude });
  },

  /** By id or case number, within the caller's scope. */
  findDetail(db: DbOrTx, idOrNumber: string, scope: Prisma.DentalCaseWhereInput) {
    return db.dentalCase.findFirst({ where: { AND: [scope, { OR: [{ id: idOrNumber }, { caseNumber: idOrNumber }] }] }, include: caseDetailInclude });
  },

  findDetailById(db: DbOrTx, id: string) {
    return db.dentalCase.findUniqueOrThrow({ where: { id }, include: caseDetailInclude });
  },

  async statusCounts(db: DbOrTx, where: Prisma.DentalCaseWhereInput) {
    const rows = await db.dentalCase.groupBy({ by: ['status'], where, _count: { _all: true } });
    return Object.fromEntries(rows.map((r) => [r.status, r._count._all])) as Partial<Record<CaseStatus, number>>;
  },
};
