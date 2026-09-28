/**
 * REST contract for case workflow steps. Each step is a POST to a sub-resource
 * of the case; both sides convert to/from the internal CaseActionPayload so
 * validation (validateActionInput) and permissions (canPerformAction) are one.
 *
 *   POST /cases/:id/status    { status, note?, payment? }        accept, review, production, QC hand-off, confirm, cancel…
 *   POST /cases/:id/assign    { technicianId, note? }
 *   POST /cases/:id/qc        { result: 'pass' | 'fail', issues, notes }
 *   POST /cases/:id/rework    { note? }
 *   POST /cases/:id/delivery  { status: 'out_for_delivery' | 'delivered', method, courierName?, deliveredTo?, receivedBy?, notes? }
 */
import type { CaseActionPayload, CaseStatus, DeliveryMethod, PaymentMethod, QcIssue } from './types';
import { ACTION_ENDPOINT, resolveTransition, CASE_ACTIONS, type CaseActionEndpoint } from './workflow';

export interface CaseStatusRequest {
  status: CaseStatus;
  note?: string;
  /** Accepting a submission may record an up-front payment. */
  payment?: { amount: number; method: PaymentMethod; reference?: string } | null;
}

export interface CaseAssignRequest {
  technicianId: string;
  note?: string;
}

export interface CaseQcRequest {
  result: 'pass' | 'fail';
  issues: QcIssue[];
  notes: string;
}

export interface CaseReworkRequest {
  note?: string;
}

export interface CaseDeliveryRequest {
  status: 'out_for_delivery' | 'delivered';
  method: DeliveryMethod;
  courierName?: string;
  deliveredTo?: string;
  receivedBy?: string;
  notes?: string;
}

export type CaseRequestBody = CaseStatusRequest | CaseAssignRequest | CaseQcRequest | CaseReworkRequest | CaseDeliveryRequest;

/** Client side: the endpoint and body for a workflow action. */
export function toCaseRequest(p: CaseActionPayload): { endpoint: CaseActionEndpoint; body: CaseRequestBody } {
  const endpoint = ACTION_ENDPOINT[p.action];
  switch (endpoint) {
    case 'assign':
      return { endpoint, body: { technicianId: p.technicianId ?? '', note: p.note } };
    case 'qc':
      return { endpoint, body: { result: p.action === 'qc_pass' ? 'pass' : 'fail', issues: p.qc?.issues ?? [], notes: p.qc?.notes || p.note || '' } };
    case 'rework':
      return { endpoint, body: { note: p.note } };
    case 'delivery':
      return {
        endpoint,
        body: {
          status: p.action === 'dispatch' ? 'out_for_delivery' : 'delivered',
          method: p.delivery?.method as DeliveryMethod,
          courierName: p.delivery?.courierName,
          deliveredTo: p.delivery?.deliveredTo,
          receivedBy: p.delivery?.receivedBy,
          notes: p.delivery?.notes || p.note,
        },
      };
    default:
      return { endpoint, body: { status: CASE_ACTIONS[p.action].to, note: p.note, payment: p.payment ?? undefined } };
  }
}

/**
 * Server side: the workflow action a request asks for on a case in `current`
 * status, or null when the request is not a valid transition from there (409).
 * Assumes the body already passed its schema.
 */
export function fromCaseRequest(endpoint: CaseActionEndpoint, body: CaseRequestBody, current: CaseStatus): CaseActionPayload | null {
  switch (endpoint) {
    case 'status': {
      const b = body as CaseStatusRequest;
      const action = resolveTransition(current, b.status);
      if (!action || ACTION_ENDPOINT[action] !== 'status') return null;
      return { action, note: b.note, payment: action === 'accept' ? b.payment ?? null : null };
    }
    case 'assign': {
      const b = body as CaseAssignRequest;
      return { action: 'assign', technicianId: b.technicianId, note: b.note };
    }
    case 'qc': {
      const b = body as CaseQcRequest;
      const fail = b.result === 'fail';
      return { action: fail ? 'qc_fail' : 'qc_pass', note: b.notes, qc: { issues: b.issues, notes: b.notes, reworkRequired: fail } };
    }
    case 'rework':
      return { action: 'start_rework', note: (body as CaseReworkRequest).note };
    case 'delivery': {
      const b = body as CaseDeliveryRequest;
      return {
        action: b.status === 'out_for_delivery' ? 'dispatch' : 'deliver',
        note: b.notes,
        delivery: { method: b.method, courierName: b.courierName, deliveredTo: b.deliveredTo, receivedBy: b.receivedBy, notes: b.notes },
      };
    }
  }
}

/** Action-payload paths (from validateActionInput) → request body fields, per endpoint. */
const FIELD_OF: Partial<Record<CaseActionEndpoint, Record<string, string>>> = {
  qc: { 'qc.issues': 'issues', note: 'notes' },
  delivery: { 'delivery.method': 'method', 'delivery.courierName': 'courierName', 'delivery.receivedBy': 'receivedBy', note: 'notes' },
};

function remap<T>(errors: Record<string, T>, map: Record<string, string>) {
  return Object.fromEntries(Object.entries(errors).map(([k, v]) => [map[k] ?? k, v]));
}

/** Server: 422 keys named after the request body the client sent. */
export function toRequestErrors<T>(endpoint: CaseActionEndpoint, errors: Record<string, T>) {
  return remap(errors, FIELD_OF[endpoint] ?? {});
}

/** Client: 422 keys back in action-payload terms (what the action dialog maps to its fields). */
export function fromRequestErrors<T>(endpoint: CaseActionEndpoint, errors: Record<string, T>) {
  const map = FIELD_OF[endpoint] ?? {};
  return remap(errors, Object.fromEntries(Object.entries(map).map(([a, b]) => [b, a])));
}
