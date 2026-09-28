/**
 * The only code path that records money. Runs inside the caller's transaction:
 * locks the invoice row (SELECT … FOR UPDATE) so two cashiers cannot both take
 * the last balance, validates with the shared rules (remaining = total − paid,
 * amount > 0 and ≤ remaining, reference for non-cash) and keeps
 * invoices.amount_paid equal to the sum of its payments.
 */
import { paymentReferenceRequired, round2, validatePaymentAmount } from '@48hrs/shared/billing';
import type { PaymentMethod } from '@48hrs/shared/types';
import { notFound, throwIfErrors, type FieldErrors } from '../lib/errors.ts';
import type { Tx } from '../lib/prisma.ts';
import { invoiceFigures } from '../repositories/mappers.ts';

export interface PaymentInput {
  amount: number;
  method: PaymentMethod;
  reference?: string;
  notes?: string;
  paidAt?: Date;
}

/** Field names used in the 422 body (callers can prefix them, e.g. "payment."). */
export async function recordPaymentTx(tx: Tx, invoiceId: string, input: PaymentInput, receivedById: string, opts: { now?: number; fieldPrefix?: string } = {}) {
  const now = opts.now ?? Date.now();
  const prefix = opts.fieldPrefix ?? '';
  await tx.$queryRaw`SELECT id FROM invoices WHERE id = ${invoiceId} FOR UPDATE`;
  const invoice = await tx.invoice.findUnique({ where: { id: invoiceId } });
  if (!invoice) throw notFound();

  const { remaining } = invoiceFigures(invoice, now);
  const errors: FieldErrors = {};
  const amountError = validatePaymentAmount(input.amount, remaining);
  if (amountError) errors[`${prefix}amount`] = [amountError];
  if (paymentReferenceRequired(input.method) && !input.reference?.trim()) errors[`${prefix}reference`] = ['Enter the transaction reference.'];
  if (input.paidAt && input.paidAt.getTime() > now + 60_000) errors[`${prefix}paidAt`] = ['Payment date cannot be in the future.'];
  throwIfErrors(errors);

  const amount = round2(input.amount);
  const payment = await tx.payment.create({
    data: {
      invoiceId,
      amount,
      method: input.method,
      reference: input.reference?.trim() ?? '',
      notes: input.notes?.trim() ?? '',
      receivedById,
      paidAt: input.paidAt ?? new Date(now),
    },
  });
  await tx.invoice.update({ where: { id: invoiceId }, data: { amountPaid: { increment: amount } } });
  return { payment, invoice };
}
