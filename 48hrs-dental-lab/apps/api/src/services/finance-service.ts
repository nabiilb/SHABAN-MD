/**
 * Invoices and payments. Remaining = Total − Paid is derived in exactly one
 * place (mappers.invoiceFigures); payments are only written by the ledger.
 */
import { round2 } from '@48hrs/shared/billing';
import { notify, RECIPIENTS } from '@48hrs/shared/notifications';
import type { recordPaymentSchema } from '@48hrs/shared/schemas';
import type { InvoiceDetail, InvoiceListItem, Paginated, PaymentListItem, PaymentMethod, PaymentStatus } from '@48hrs/shared/types';
import type { z } from 'zod';
import { Prisma } from '../generated/prisma/client.ts';
import { notFound, validation } from '../lib/errors.ts';
import { prisma } from '../lib/prisma.ts';
import { notifyUsers, recipients } from '../notifications/dispatcher.ts';
import { caseScope, clinicScope } from '../policies/case-policy.ts';
import { logActivity } from '../repositories/activity-repository.ts';
import { invoiceListInclude, paymentInclude, toInvoiceListItem, toPayment } from '../repositories/mappers.ts';
import { dirSql, inIdOrder, orderedIdPage, type Direction } from '../repositories/ordering.ts';
import { readLabSettings } from '../repositories/settings-repository.ts';
import type { AuthContext } from '../types/auth.ts';
import { labDayEnd, labDayStart } from '../utils/dates.ts';
import { paginate, type PageRequest } from '../utils/query.ts';
import { issueInvoice } from './invoice-issuer.ts';
import { recordPaymentTx } from './payment-ledger.ts';

const insensitive = (v: string) => ({ contains: v, mode: 'insensitive' as const });
const NONE = '__none__';

export interface InvoiceQuery {
  search?: string;
  status?: PaymentStatus;
  clinicId?: string;
  doctorId?: string;
  from?: string;
  to?: string;
  sort?: string;
  dir: Direction;
}

export interface PaymentQuery {
  search?: string;
  method?: PaymentMethod;
  clinicId?: string;
  from?: string;
  to?: string;
  sort?: string;
  dir: Direction;
}

function statusWhere(status: PaymentStatus, now: Date): Prisma.InvoiceWhereInput {
  const total = prisma.invoice.fields.total;
  switch (status) {
    case 'paid':
      return { amountPaid: { gte: total } };
    case 'overdue':
      return { amountPaid: { lt: total }, dueDate: { lt: now } };
    case 'partial':
      return { amountPaid: { gt: 0, lt: total }, dueDate: { gte: now } };
    case 'unpaid':
      return { amountPaid: 0, total: { gt: 0 }, dueDate: { gte: now } };
  }
}

function dayRange(from?: string, to?: string) {
  return from || to ? { ...(from ? { gte: labDayStart(from) } : {}), ...(to ? { lt: labDayEnd(to) } : {}) } : undefined;
}

async function detail(id: string, now = Date.now()): Promise<InvoiceDetail> {
  const inv = await prisma.invoice.findUniqueOrThrow({
    where: { id },
    include: { ...invoiceListInclude, case: { select: { caseNumber: true, restorationType: true, teeth: true, units: true, unitPrice: true } }, payments: { include: paymentInclude, orderBy: { paidAt: 'desc' } } },
  });
  const c = inv.case;
  const units = c.units || 1;
  const subtotal = inv.subtotal.toNumber();
  const emergencyFee = inv.emergencyFee.toNumber();
  const lineItems = [
    { description: `${c.restorationType}${c.teeth.length ? ` — teeth ${c.teeth.join(', ')}` : ''}`, quantity: units, unitPrice: c.unitPrice.toNumber(), amount: subtotal },
  ];
  if (emergencyFee > 0) lineItems.push({ description: 'Emergency turnaround', quantity: units, unitPrice: round2(emergencyFee / units), amount: emergencyFee });
  return { ...toInvoiceListItem(inv, now), payments: inv.payments.map(toPayment), lineItems };
}

export const financeService = {
  async listInvoices(auth: AuthContext, q: InvoiceQuery, page: PageRequest): Promise<Paginated<InvoiceListItem>> {
    const now = Date.now();
    const scope = clinicScope(auth);
    const where: Prisma.InvoiceWhereInput = {
      AND: [
        scope === null ? {} : { clinicId: scope || NONE },
        q.clinicId ? { clinicId: q.clinicId } : {},
        q.doctorId ? { doctorId: q.doctorId } : {},
        dayRange(q.from, q.to) ? { issuedAt: dayRange(q.from, q.to) } : {},
        q.status ? statusWhere(q.status, new Date(now)) : {},
        q.search
          ? { OR: [{ invoiceNumber: insensitive(q.search) }, { case: { caseNumber: insensitive(q.search) } }, { patient: { name: insensitive(q.search) } }, { clinic: { name: insensitive(q.search) } }, { doctor: { name: insensitive(q.search) } }] }
          : {},
      ],
    };
    const total = await prisma.invoice.count({ where });
    const { skip, take, meta } = paginate(page, total);
    const sort = q.sort ?? 'issuedAt';
    let rows;
    if (sort === 'remaining' || sort === 'status') {
      // Balance and status are derived from total / amount paid / due date — ordered in SQL.
      const at = new Date(now);
      const expr =
        sort === 'remaining'
          ? Prisma.sql`GREATEST(total - "amountPaid", 0)`
          : Prisma.sql`CASE WHEN "amountPaid" >= total THEN 'paid' WHEN "dueDate" < ${at} THEN 'overdue' WHEN "amountPaid" > 0 THEN 'partial' ELSE 'unpaid' END`;
      const ids = (await prisma.invoice.findMany({ where, select: { id: true } })).map((r) => r.id);
      const pageIds = await orderedIdPage(prisma, (list) => Prisma.sql`SELECT id FROM invoices WHERE id = ANY(${list}) ORDER BY ${expr} ${dirSql(q.dir)}, "issuedAt" DESC, id`, ids, skip, take);
      rows = inIdOrder(await prisma.invoice.findMany({ where: { id: { in: pageIds } }, include: invoiceListInclude }), pageIds);
    } else {
      const orders: Record<string, Prisma.InvoiceOrderByWithRelationInput[]> = {
        invoiceNumber: [{ invoiceNumber: q.dir }],
        issuedAt: [{ issuedAt: q.dir }, { invoiceNumber: q.dir }],
        dueDate: [{ dueDate: q.dir }, { invoiceNumber: q.dir }],
        total: [{ total: q.dir }, { invoiceNumber: q.dir }],
        paid: [{ amountPaid: q.dir }, { invoiceNumber: q.dir }],
        clinic: [{ clinic: { name: q.dir } }, { invoiceNumber: q.dir }],
      };
      rows = await prisma.invoice.findMany({ where, orderBy: orders[sort] ?? orders.issuedAt, skip, take, include: invoiceListInclude });
    }
    return { data: rows.map((r) => toInvoiceListItem(r, now)), meta };
  },

  async getInvoice(auth: AuthContext, idOrNumber: string): Promise<InvoiceDetail> {
    const scope = clinicScope(auth);
    const inv = await prisma.invoice.findFirst({ where: { AND: [{ OR: [{ id: idOrNumber }, { invoiceNumber: idOrNumber }] }, scope === null ? {} : { clinicId: scope || NONE }] }, select: { id: true } });
    if (!inv) throw notFound();
    return detail(inv.id);
  },

  /** Issues the invoice for a received case that has none (acceptance normally does this). */
  async createInvoice(auth: AuthContext, caseId: string): Promise<InvoiceDetail> {
    const c = await prisma.dentalCase.findFirst({ where: { AND: [caseScope(auth), { OR: [{ id: caseId }, { caseNumber: caseId }] }] }, include: { invoice: { select: { id: true } } } });
    if (!c) throw validation({ caseId: ['Choose a valid case.'] });
    if (c.invoice) throw validation({ caseId: ['This case already has an invoice.'] });
    if (!c.receivedAt || c.status === 'cancelled' || c.status === 'rejected') throw validation({ caseId: ['Only cases received by the lab can be invoiced.'] });
    const settings = await readLabSettings(prisma);
    const inv = await prisma.$transaction(async (tx) => {
      const created = await issueInvoice(tx, c, settings);
      await logActivity(tx, auth.user, { action: 'invoice.create', description: `Issued ${created.invoiceNumber} for ${c.caseNumber}`, subjectType: 'invoice', subjectId: created.id, subjectLabel: created.invoiceNumber });
      return created;
    });
    return detail(inv.id);
  },

  async recordPayment(auth: AuthContext, body: z.output<typeof recordPaymentSchema>): Promise<InvoiceDetail> {
    const scope = clinicScope(auth);
    const inv = await prisma.invoice.findFirst({ where: { id: body.invoiceId, ...(scope === null ? {} : { clinicId: scope || NONE }) }, select: { id: true, invoiceNumber: true, caseId: true } });
    if (!inv) throw notFound();
    await prisma.$transaction(async (tx) => {
      const { payment } = await recordPaymentTx(tx, inv.id, { amount: body.amount, method: body.method, reference: body.reference, notes: body.notes, paidAt: body.paidAt ? new Date(body.paidAt) : undefined }, auth.user.id);
      const amount = payment.amount.toNumber();
      await logActivity(tx, auth.user, { action: 'payment.record', description: `Recorded ${amount.toFixed(2)} on ${inv.invoiceNumber}`, subjectType: 'invoice', subjectId: inv.id, subjectLabel: inv.invoiceNumber });
      await notifyUsers(tx, await recipients(tx, RECIPIENTS.finance), notify.paymentReceived(amount, inv.invoiceNumber), { caseId: inv.caseId, exceptUserId: auth.user.id });
    });
    return detail(inv.id);
  },

  async listPayments(auth: AuthContext, q: PaymentQuery, page: PageRequest): Promise<Paginated<PaymentListItem>> {
    const scope = clinicScope(auth);
    const where: Prisma.PaymentWhereInput = {
      AND: [
        scope === null ? {} : { invoice: { clinicId: scope || NONE } },
        q.method ? { method: q.method } : {},
        q.clinicId ? { invoice: { clinicId: q.clinicId } } : {},
        dayRange(q.from, q.to) ? { paidAt: dayRange(q.from, q.to) } : {},
        q.search
          ? { OR: [{ reference: insensitive(q.search) }, { invoice: { invoiceNumber: insensitive(q.search) } }, { invoice: { case: { caseNumber: insensitive(q.search) } } }, { invoice: { clinic: { name: insensitive(q.search) } } }, { receivedBy: { name: insensitive(q.search) } }] }
          : {},
      ],
    };
    const orders: Record<string, Prisma.PaymentOrderByWithRelationInput[]> = {
      paidAt: [{ paidAt: q.dir }, { id: q.dir }],
      amount: [{ amount: q.dir }, { paidAt: 'desc' }],
      method: [{ method: q.dir }, { paidAt: 'desc' }],
      invoiceNumber: [{ invoice: { invoiceNumber: q.dir } }, { paidAt: 'desc' }],
      clinic: [{ invoice: { clinic: { name: q.dir } } }, { paidAt: 'desc' }],
    };
    const total = await prisma.payment.count({ where });
    const { skip, take, meta } = paginate(page, total);
    const rows = await prisma.payment.findMany({
      where,
      orderBy: orders[q.sort ?? 'paidAt'] ?? orders.paidAt,
      skip,
      take,
      include: { ...paymentInclude, invoice: { select: { invoiceNumber: true, caseId: true, case: { select: { caseNumber: true } }, clinic: { select: { id: true, name: true } } } } },
    });
    return {
      data: rows.map((p) => ({ ...toPayment(p), invoiceNumber: p.invoice.invoiceNumber, caseId: p.invoice.caseId, caseNumber: p.invoice.case.caseNumber, clinic: p.invoice.clinic })),
      meta,
    };
  },
};
