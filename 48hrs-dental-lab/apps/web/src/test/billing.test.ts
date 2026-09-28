import { describe, expect, it } from 'vitest';
import { invoiceStatus, invoiceTotals, priceCase, unitsFor, validatePaymentAmount } from '@48hrs/shared/billing';

describe('pricing', () => {
  const zirconia = { unitMode: 'tooth' as const, unitPrice: 20 };
  it('charges per selected tooth (prototype: 3 × $20 = $60)', () => {
    expect(priceCase(zirconia, [14, 15, 16], null, false, 5)).toEqual({ units: 3, unitPrice: 20, subtotal: 60, emergencyFee: 0, total: 60 });
  });
  it('adds the emergency fee per unit', () => {
    expect(priceCase(zirconia, [14, 15, 16], null, true, 5).total).toBe(75);
  });
  it('counts denture arches and appliances', () => {
    expect(unitsFor({ unitMode: 'denture' }, [], 'upper_lower')).toBe(2);
    expect(unitsFor({ unitMode: 'arch' }, [])).toBe(1);
  });
});

describe('payment calculation', () => {
  const future = new Date(Date.now() + 86_400_000).toISOString();
  const past = new Date(Date.now() - 86_400_000).toISOString();
  it('derives paid, remaining and status', () => {
    expect(invoiceTotals(100, [], future)).toEqual({ paid: 0, remaining: 100, status: 'unpaid' });
    expect(invoiceTotals(100, [{ amount: 30 }, { amount: 20.5 }], future)).toEqual({ paid: 50.5, remaining: 49.5, status: 'partial' });
    expect(invoiceTotals(100, [{ amount: 100 }], past)).toEqual({ paid: 100, remaining: 0, status: 'paid' });
  });
  it('marks unpaid balances past the due date as overdue', () => {
    expect(invoiceStatus(100, 40, past)).toBe('overdue');
  });
  it('rejects zero, negative and over-payments', () => {
    expect(validatePaymentAmount(0, 50)).toMatch(/greater than zero/);
    expect(validatePaymentAmount(50.01, 50)).toMatch(/cannot exceed/);
    expect(validatePaymentAmount(50, 50)).toBeNull();
  });
});
