import type { DentureType, LabService, Payment, PaymentStatus } from './models';

export const DENTURE_TYPES: { value: DentureType; label: string; units: number }[] = [
  { value: 'full_upper', label: 'Full Upper', units: 1 },
  { value: 'full_lower', label: 'Full Lower', units: 1 },
  { value: 'upper_lower', label: 'Upper + Lower', units: 2 },
  { value: 'partial', label: 'Partial', units: 1 },
];

/** Units follow the prototype: one per tooth, per denture arch, or one per appliance. */
export function unitsFor(service: Pick<LabService, 'unitMode'>, teeth: number[], dentureType?: DentureType | null): number {
  if (service.unitMode === 'tooth') return teeth.length;
  if (service.unitMode === 'denture') return DENTURE_TYPES.find((d) => d.value === dentureType)?.units ?? 1;
  return 1;
}

export interface CasePrice {
  units: number;
  unitPrice: number;
  subtotal: number;
  emergencyFee: number;
  total: number;
}

export function priceCase(
  service: Pick<LabService, 'unitMode' | 'unitPrice'>,
  teeth: number[],
  dentureType: DentureType | null | undefined,
  urgent: boolean,
  emergencyFeePerUnit: number,
): CasePrice {
  const units = unitsFor(service, teeth, dentureType);
  const subtotal = round2(service.unitPrice * units);
  const emergencyFee = urgent ? round2(emergencyFeePerUnit * units) : 0;
  return { units, unitPrice: service.unitPrice, subtotal, emergencyFee, total: round2(subtotal + emergencyFee) };
}

export function round2(n: number) {
  return Math.round(n * 100) / 100;
}

export function sumPayments(payments: Pick<Payment, 'amount'>[]) {
  return round2(payments.reduce((s, p) => s + p.amount, 0));
}

/** Unpaid / Partial / Paid, and Overdue when money is still owed after the due date. */
export function invoiceStatus(total: number, paid: number, dueDate: string | Date, now: number = Date.now()): PaymentStatus {
  const remaining = round2(total - paid);
  if (remaining <= 0) return 'paid';
  if (new Date(dueDate).getTime() < now) return 'overdue';
  return paid > 0 ? 'partial' : 'unpaid';
}

export function invoiceTotals(total: number, payments: Pick<Payment, 'amount'>[], dueDate: string | Date, now?: number) {
  const paid = sumPayments(payments);
  const remaining = Math.max(0, round2(total - paid));
  return { paid, remaining, status: invoiceStatus(total, paid, dueDate, now) };
}

/** Validates a payment against what is still owed. Returns an error message or null. */
export function validatePaymentAmount(amount: number, remaining: number): string | null {
  if (!Number.isFinite(amount) || amount <= 0) return 'Enter an amount greater than zero.';
  if (round2(amount) > round2(remaining)) return `Amount cannot exceed the remaining balance of ${remaining.toFixed(2)}.`;
  return null;
}

/** Non-cash payments must carry a transaction reference (bank / mobile-money receipts). */
export function paymentReferenceRequired(method: string | undefined | null) {
  return !!method && method !== 'cash';
}

/** Balance left after a payment — used for previews; the API recomputes it authoritatively. */
export function remainingAfter(remaining: number, amount: number) {
  return Math.max(0, round2(remaining - (Number.isFinite(amount) ? amount : 0)));
}
