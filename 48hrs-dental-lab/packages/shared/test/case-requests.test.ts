import { describe, expect, it } from 'vitest';
import { fromCaseRequest, fromRequestErrors, toCaseRequest, toRequestErrors } from '../src/case-requests';
import { CASE_ACTIONS, resolveTransition } from '../src/workflow';
import type { CaseActionKey, CaseActionPayload } from '../src/types';

describe('REST contract for workflow steps', () => {
  it('every (from, to) pair maps to exactly one action', () => {
    for (const def of Object.values(CASE_ACTIONS)) for (const from of def.from) expect(resolveTransition(from, def.to)).toBe(def.key);
    expect(resolveTransition('received', 'delivered')).toBeNull();
  });

  it('client → server round trip gives back the same action for every step', () => {
    const payloads: CaseActionPayload[] = (Object.keys(CASE_ACTIONS) as CaseActionKey[]).map((action) => ({
      action,
      note: 'n',
      technicianId: 'tec_1',
      payment: action === 'accept' ? { amount: 5, method: 'cash' } : null,
      qc: { issues: ['fit'], notes: 'n', reworkRequired: action === 'qc_fail' },
      delivery: { method: 'lab_courier', courierName: 'X', receivedBy: 'Y' },
    }));
    for (const p of payloads) {
      const { endpoint, body } = toCaseRequest(p);
      const back = fromCaseRequest(endpoint, body, CASE_ACTIONS[p.action].from[0]);
      expect(back?.action, p.action).toBe(p.action);
    }
  });

  it('a status request that belongs to a dedicated endpoint is not a valid transition', () => {
    expect(fromCaseRequest('status', { status: 'assigned' }, 'received')).toBeNull();
    expect(fromCaseRequest('status', { status: 'ready' }, 'quality_control')).toBeNull();
    expect(fromCaseRequest('status', { status: 'in_production' }, 'assigned')).toMatchObject({ action: 'start_production' });
  });

  it('422 keys translate between payload paths and request fields', () => {
    const server = toRequestErrors('qc', { 'qc.issues': ['x'], note: ['y'] });
    expect(server).toEqual({ issues: ['x'], notes: ['y'] });
    expect(fromRequestErrors('qc', server)).toEqual({ 'qc.issues': ['x'], note: ['y'] });
    expect(toRequestErrors('delivery', { 'delivery.receivedBy': ['z'] })).toEqual({ receivedBy: ['z'] });
    expect(toRequestErrors('status', { 'payment.amount': ['a'] })).toEqual({ 'payment.amount': ['a'] });
  });
});
