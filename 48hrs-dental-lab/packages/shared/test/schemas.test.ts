import { describe, expect, it } from 'vitest';
import { caseDeliverySchema, createCaseSchema, fieldErrors, recordPaymentSchema, resetPasswordSchema, settingsSchema, userSchema } from '../src/schemas';
import { deadlineAlert } from '../src/notifications';
import { DEFAULT_SLA_CONFIG } from '../src/sla';

describe('request schemas', () => {
  it('reports every problem by field path', () => {
    const r = createCaseSchema.safeParse({ teeth: [0, 40], priority: 'asap' });
    expect(r.success).toBe(false);
    if (!r.success) expect(Object.keys(fieldErrors(r.error))).toEqual(expect.arrayContaining(['doctorId', 'serviceId', 'shade', 'priority', 'teeth.0', 'teeth.1']));
  });

  it('normalises input (trim, lower-case e-mail, defaults)', () => {
    const u = userSchema.parse({ name: '  Ana ', email: ' ANA@Lab.SO ', role: 'reception' });
    expect(u).toMatchObject({ name: 'Ana', email: 'ana@lab.so', active: true });
    expect(u.password).toBeUndefined();
    expect(caseDeliverySchema.safeParse({ status: 'delivered', method: 'drone' }).success).toBe(false);
  });

  it('enforces the password policy, confirmation and cross-field settings rules', () => {
    expect(resetPasswordSchema.safeParse({ token: 't', email: 'a@b.so', password: 'abcdefgh', passwordConfirmation: 'abcdefgh' }).success).toBe(false);
    expect(resetPasswordSchema.safeParse({ token: 't', email: 'a@b.so', password: 'abcdefg1', passwordConfirmation: 'abcdefg2' }).success).toBe(false);
    const s = settingsSchema.safeParse({ labName: 'L', currency: 'USD', slaHours: 48, atRiskHours: 4, criticalHours: 6, emergencyFeePerUnit: 0, invoiceDueDays: 0 });
    expect(s.success).toBe(false);
    expect(recordPaymentSchema.safeParse({ invoiceId: 'i', amount: '12.5', method: 'cash' }).data?.amount).toBe(12.5);
  });
});

describe('deadline alerts', () => {
  const base = { caseNumber: 'DL-1', status: 'in_production' as const, deliveredAt: null };
  const at = (hoursLeft: number) => ({ ...base, receivedAt: new Date(Date.now() - (48 - hoursLeft) * 3_600_000).toISOString(), dueAt: new Date(Date.now() + hoursLeft * 3_600_000).toISOString() });

  it('fires at risk, then overdue, once each', () => {
    expect(deadlineAlert(at(30), {}, Date.now(), DEFAULT_SLA_CONFIG)).toBeNull();
    expect(deadlineAlert(at(10), {}, Date.now(), DEFAULT_SLA_CONFIG)?.kind).toBe('at_risk');
    expect(deadlineAlert(at(10), { atRisk: true }, Date.now(), DEFAULT_SLA_CONFIG)).toBeNull();
    expect(deadlineAlert(at(-1), { atRisk: true }, Date.now(), DEFAULT_SLA_CONFIG)?.content.title).toBe('Case overdue');
    expect(deadlineAlert(at(-1), { atRisk: true, overdue: true }, Date.now(), DEFAULT_SLA_CONFIG)).toBeNull();
    expect(deadlineAlert({ ...at(-1), status: 'delivered' }, {}, Date.now(), DEFAULT_SLA_CONFIG)).toBeNull();
  });
});
