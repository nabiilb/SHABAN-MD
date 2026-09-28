/** Quality-control and delivery history (the live queues are case lists filtered by status). */
import type { DeliveryListItem, DeliveryMethod, DeliveryStatus, Paginated, QualityCheckListItem } from '@48hrs/shared/types';
import type { Prisma } from '../generated/prisma/client.ts';
import { prisma } from '../lib/prisma.ts';
import { caseScope } from '../policies/case-policy.ts';
import { deliveryInclude, qualityCheckInclude, toDelivery, toQualityCheck } from '../repositories/mappers.ts';
import type { Direction } from '../repositories/ordering.ts';
import type { AuthContext } from '../types/auth.ts';
import { paginate, type PageRequest } from '../utils/query.ts';

const insensitive = (v: string) => ({ contains: v, mode: 'insensitive' as const });
const caseRefSelect = { caseNumber: true, status: true, technician: { select: { name: true } }, patient: { select: { name: true } }, clinic: { select: { name: true } } } as const;

export interface QcQuery {
  search?: string;
  result?: 'passed' | 'failed';
  technicianId?: string;
  sort?: string;
  dir: Direction;
}

export interface DeliveryQuery {
  search?: string;
  status?: DeliveryStatus;
  method?: DeliveryMethod;
  sort?: string;
  dir: Direction;
}

export const labService = {
  async qualityChecks(auth: AuthContext, q: QcQuery, page: PageRequest): Promise<Paginated<QualityCheckListItem>> {
    const where: Prisma.QualityCheckWhereInput = {
      AND: [
        { case: caseScope(auth) },
        q.result ? { result: q.result } : {},
        q.technicianId ? { case: { technicianId: q.technicianId } } : {},
        q.search
          ? { OR: [{ notes: insensitive(q.search) }, { checkedBy: { name: insensitive(q.search) } }, { case: { caseNumber: insensitive(q.search) } }, { case: { patient: { name: insensitive(q.search) } } }, { case: { clinic: { name: insensitive(q.search) } } }] }
          : {},
      ],
    };
    const orders: Record<string, Prisma.QualityCheckOrderByWithRelationInput[]> = {
      checkedAt: [{ checkedAt: q.dir }],
      caseNumber: [{ case: { caseNumber: q.dir } }, { checkedAt: 'desc' }],
      result: [{ result: q.dir }, { checkedAt: 'desc' }],
    };
    const total = await prisma.qualityCheck.count({ where });
    const { skip, take, meta } = paginate(page, total);
    const rows = await prisma.qualityCheck.findMany({ where, orderBy: orders[q.sort ?? 'checkedAt'] ?? orders.checkedAt, skip, take, include: { ...qualityCheckInclude, case: { select: caseRefSelect } } });
    return {
      data: rows.map((r) => ({
        ...toQualityCheck(r),
        caseNumber: r.case.caseNumber,
        caseStatus: r.case.status,
        patientName: r.case.patient.name,
        clinicName: r.case.clinic.name,
        technicianName: r.case.technician?.name ?? null,
      })),
      meta,
    };
  },

  async deliveries(auth: AuthContext, q: DeliveryQuery, page: PageRequest): Promise<Paginated<DeliveryListItem>> {
    const where: Prisma.DeliveryWhereInput = {
      AND: [
        { case: caseScope(auth) },
        q.status ? { status: q.status } : {},
        q.method ? { method: q.method } : {},
        q.search
          ? { OR: [{ receivedBy: insensitive(q.search) }, { courierName: insensitive(q.search) }, { case: { caseNumber: insensitive(q.search) } }, { case: { patient: { name: insensitive(q.search) } } }, { case: { clinic: { name: insensitive(q.search) } } }] }
          : {},
      ],
    };
    const orders: Record<string, Prisma.DeliveryOrderByWithRelationInput[]> = {
      deliveredAt: [{ deliveredAt: { sort: q.dir, nulls: 'last' } }, { dispatchedAt: { sort: q.dir, nulls: 'last' } }, { createdAt: q.dir }],
      caseNumber: [{ case: { caseNumber: q.dir } }],
      clinic: [{ case: { clinic: { name: q.dir } } }, { createdAt: 'desc' }],
    };
    const total = await prisma.delivery.count({ where });
    const { skip, take, meta } = paginate(page, total);
    const rows = await prisma.delivery.findMany({ where, orderBy: orders[q.sort ?? 'deliveredAt'] ?? orders.deliveredAt, skip, take, include: { ...deliveryInclude, case: { select: caseRefSelect } } });
    return { data: rows.map((d) => ({ ...toDelivery(d), caseNumber: d.case.caseNumber, patientName: d.case.patient.name, clinicName: d.case.clinic.name })), meta };
  },
};
