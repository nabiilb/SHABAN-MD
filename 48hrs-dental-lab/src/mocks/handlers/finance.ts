import { round2, validatePaymentAmount } from '@/lib/billing';
import { PAYMENT_METHOD_LABELS } from '@/lib/constants';
import { PERMISSIONS, hasPermission } from '@/lib/permissions';
import { ApiError } from '@/services/api/errors';
import type { RecordPaymentPayload } from '@/types/api';
import type { InvoiceDetail, InvoiceListItem, PaymentListItem, PaymentMethod } from '@/types/models';
import { localDay } from '@/utils/dates';
import { authenticate, authorize } from '../auth-context';
import { nextId, type MockDatabase } from '../db';
import { invoiceView, logActivity, notifyUsers, usersWithPermission } from '../domain';
import { includesText, paginate, qStr, route, sortItems, validationError, type AuthedContext } from '../router';

function scopeClinic(ctx: AuthedContext) {
  return hasPermission(ctx.permissions, PERMISSIONS.CASES_VIEW_ALL) ? null : ctx.user.clinicId ?? '__none__';
}

function invoiceItem(db: MockDatabase, inv: MockDatabase['invoices'][number], now: number): InvoiceListItem {
  const c = db.cases.find((x) => x.id === inv.caseId);
  const p = db.patients.find((x) => x.id === inv.patientId);
  const d = db.doctors.find((x) => x.id === inv.doctorId);
  const k = db.clinics.find((x) => x.id === inv.clinicId);
  return {
    ...invoiceView(db, inv, now),
    caseNumber: c?.caseNumber ?? '—',
    patient: { id: inv.patientId, name: p?.name ?? '—' },
    doctor: { id: inv.doctorId, name: d?.name ?? '—' },
    clinic: { id: inv.clinicId, name: k?.name ?? '—' },
  };
}

function invoiceDetail(db: MockDatabase, inv: MockDatabase['invoices'][number], now: number): InvoiceDetail {
  const c = db.cases.find((x) => x.id === inv.caseId);
  const lineItems = [
    { description: c ? `${c.restorationType}${c.teeth.length ? ` — teeth ${c.teeth.join(', ')}` : ''}` : 'Laboratory work', quantity: c?.units ?? 1, unitPrice: c?.unitPrice ?? inv.subtotal, amount: inv.subtotal },
  ];
  if (inv.emergencyFee > 0) lineItems.push({ description: 'Emergency turnaround', quantity: c?.units ?? 1, unitPrice: round2(inv.emergencyFee / (c?.units || 1)), amount: inv.emergencyFee });
  return {
    ...invoiceItem(db, inv, now),
    payments: db.payments.filter((p) => p.invoiceId === inv.id).sort((a, b) => b.paidAt.localeCompare(a.paidAt)),
    lineItems,
  };
}

route('GET', '/invoices', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.INVOICES_VIEW);
  const q = raw.query;
  const scope = scopeClinic(ctx);
  const from = qStr(q, 'from');
  const to = qStr(q, 'to');
  const items = ctx.db.invoices
    .filter((i) => !scope || i.clinicId === scope)
    .filter((i) => !qStr(q, 'clinicId') || i.clinicId === qStr(q, 'clinicId'))
    .filter((i) => !qStr(q, 'doctorId') || i.doctorId === qStr(q, 'doctorId'))
    .filter((i) => (!from || localDay(i.issuedAt) >= from) && (!to || localDay(i.issuedAt) <= to))
    .map((i) => invoiceItem(ctx.db, i, ctx.now))
    .filter((i) => !qStr(q, 'status') || i.status === qStr(q, 'status'))
    .filter((i) => includesText([i.invoiceNumber, i.caseNumber, i.patient.name, i.clinic.name, i.doctor.name], qStr(q, 'search')));
  return paginate(
    sortItems(items, q, { invoiceNumber: (i) => i.invoiceNumber, issuedAt: (i) => i.issuedAt, dueDate: (i) => i.dueDate, total: (i) => i.total, paid: (i) => i.paid, remaining: (i) => i.remaining, status: (i) => i.status, clinic: (i) => i.clinic.name }, 'issuedAt'),
    q,
  );
});

route('GET', '/invoices/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.INVOICES_VIEW);
  const inv = ctx.db.invoices.find((i) => i.id === raw.params.id || i.invoiceNumber === raw.params.id);
  const scope = scopeClinic(ctx);
  if (!inv || (scope && inv.clinicId !== scope)) throw new ApiError(404, 'Invoice not found.');
  return invoiceDetail(ctx.db, inv, ctx.now);
});

route('POST', '/invoices/:id/payments', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.PAYMENTS_RECORD);
  const inv = ctx.db.invoices.find((i) => i.id === raw.params.id);
  if (!inv) throw new ApiError(404, 'Invoice not found.');
  const body = (raw.body ?? {}) as Partial<RecordPaymentPayload>;
  const amount = Number(body.amount);
  const view = invoiceView(ctx.db, inv, ctx.now);
  const errors: Record<string, string[]> = {};
  const amountError = validatePaymentAmount(amount, view.remaining);
  if (amountError) errors.amount = [amountError];
  if (!body.method || !(body.method in PAYMENT_METHOD_LABELS)) errors.method = ['Choose a payment method.'];
  if (body.method && body.method !== 'cash' && !body.reference?.trim()) errors.reference = ['Enter the transaction reference.'];
  if (body.paidAt && new Date(body.paidAt).getTime() > ctx.now + 60_000) errors.paidAt = ['Payment date cannot be in the future.'];
  if (Object.keys(errors).length) throw validationError(errors);

  ctx.db.payments.push({
    id: nextId('pay'),
    invoiceId: inv.id,
    amount: round2(amount),
    method: body.method as PaymentMethod,
    reference: body.reference?.trim() ?? '',
    notes: body.notes?.trim() ?? '',
    receivedById: ctx.user.id,
    receivedByName: ctx.user.name,
    paidAt: body.paidAt ? new Date(body.paidAt).toISOString() : new Date(ctx.now).toISOString(),
  });
  logActivity(ctx.db, ctx.user, { action: 'payment.record', description: `Recorded ${amount.toFixed(2)} on ${inv.invoiceNumber}`, subjectType: 'invoice', subjectId: inv.id, subjectLabel: inv.invoiceNumber }, ctx.now);
  const c = ctx.db.cases.find((x) => x.id === inv.caseId);
  notifyUsers(ctx.db, usersWithPermission(ctx.db, PERMISSIONS.REPORTS_FINANCIAL, ['admin', 'super_admin']), { type: 'payment_received', title: 'Payment received', message: `${amount.toFixed(2)} received on ${inv.invoiceNumber}.`, c }, ctx.now, ctx.user.id);
  return invoiceDetail(ctx.db, inv, ctx.now);
});

route('GET', '/payments', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.PAYMENTS_VIEW);
  const q = raw.query;
  const from = qStr(q, 'from');
  const to = qStr(q, 'to');
  const items: PaymentListItem[] = ctx.db.payments
    .map((p) => {
      const inv = ctx.db.invoices.find((i) => i.id === p.invoiceId);
      const c = inv ? ctx.db.cases.find((x) => x.id === inv.caseId) : undefined;
      const k = inv ? ctx.db.clinics.find((x) => x.id === inv.clinicId) : undefined;
      return { ...p, invoiceNumber: inv?.invoiceNumber ?? '—', caseId: inv?.caseId ?? '', caseNumber: c?.caseNumber ?? '—', clinic: { id: k?.id ?? '', name: k?.name ?? '—' } };
    })
    .filter((p) => !qStr(q, 'method') || p.method === qStr(q, 'method'))
    .filter((p) => !qStr(q, 'clinicId') || p.clinic.id === qStr(q, 'clinicId'))
    .filter((p) => (!from || localDay(p.paidAt) >= from) && (!to || localDay(p.paidAt) <= to))
    .filter((p) => includesText([p.invoiceNumber, p.caseNumber, p.clinic.name, p.reference, p.receivedByName], qStr(q, 'search')));
  return paginate(sortItems(items, q, { paidAt: (p) => p.paidAt, amount: (p) => p.amount, method: (p) => p.method, invoiceNumber: (p) => p.invoiceNumber, clinic: (p) => p.clinic.name }, 'paidAt'), q);
});
