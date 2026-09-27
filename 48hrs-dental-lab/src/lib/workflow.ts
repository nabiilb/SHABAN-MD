import type { CaseActionKey } from '@/types/api';
import type { CaseStatus, LabCase, User } from '@/types/models';
import { PERMISSIONS, hasPermission } from './permissions';

export type Tone = 'info' | 'success' | 'warning' | 'danger' | 'neutral' | 'navy';

export interface StatusMeta {
  label: string;
  tone: Tone;
  /** Index on the Received → Delivered timeline (see WORKFLOW_STAGES). -1 = pre-intake. */
  stage: number;
  /** Who acts next, shown as "Next action: …" (from the prototype). */
  nextActor: string | null;
  open: boolean;
}

export const STATUS_META: Record<CaseStatus, StatusMeta> = {
  submitted: { label: 'Submitted', tone: 'info', stage: -1, nextActor: 'Reception', open: true },
  correction: { label: 'Correction requested', tone: 'warning', stage: -1, nextActor: 'Clinic', open: true },
  received: { label: 'Received', tone: 'info', stage: 0, nextActor: 'Lab Manager', open: true },
  review: { label: 'In review', tone: 'info', stage: 1, nextActor: 'Lab Manager', open: true },
  assigned: { label: 'Assigned', tone: 'info', stage: 2, nextActor: 'Technician', open: true },
  in_production: { label: 'In production', tone: 'info', stage: 3, nextActor: 'Technician', open: true },
  rework: { label: 'Rework required', tone: 'danger', stage: 3, nextActor: 'Technician', open: true },
  quality_control: { label: 'Quality control', tone: 'warning', stage: 4, nextActor: 'Quality Control', open: true },
  ready: { label: 'Ready', tone: 'success', stage: 5, nextActor: 'Delivery', open: true },
  out_for_delivery: { label: 'Out for delivery', tone: 'success', stage: 5, nextActor: 'Delivery', open: true },
  delivered: { label: 'Delivered', tone: 'success', stage: 6, nextActor: 'Clinic (confirm receipt)', open: false },
  completed: { label: 'Completed', tone: 'neutral', stage: 6, nextActor: null, open: false },
  cancelled: { label: 'Cancelled', tone: 'neutral', stage: -1, nextActor: null, open: false },
  rejected: { label: 'Rejected', tone: 'danger', stage: -1, nextActor: null, open: false },
};

export const ALL_STATUSES = Object.keys(STATUS_META) as CaseStatus[];
export const OPEN_STATUSES = ALL_STATUSES.filter((s) => STATUS_META[s].open);
/** Statuses where the lab owns the case and the 48-hour clock is running. */
export const IN_LAB_STATUSES: CaseStatus[] = [
  'received',
  'review',
  'assigned',
  'in_production',
  'rework',
  'quality_control',
  'ready',
  'out_for_delivery',
];
export const DONE_STATUSES: CaseStatus[] = ['delivered', 'completed'];
export const PRODUCTION_STATUSES: CaseStatus[] = ['assigned', 'in_production', 'rework'];

/** The seven production stages shown in the case timeline. */
export const WORKFLOW_STAGES = [
  { key: 'received', label: 'Received' },
  { key: 'review', label: 'Review' },
  { key: 'assigned', label: 'Assigned' },
  { key: 'in_production', label: 'In Production' },
  { key: 'quality_control', label: 'Quality Control' },
  { key: 'ready', label: 'Ready' },
  { key: 'delivered', label: 'Delivered' },
] as const;

export interface ActionDef {
  key: CaseActionKey;
  label: string;
  from: CaseStatus[];
  to: CaseStatus;
  permission: string;
  variant: 'primary' | 'secondary' | 'danger' | 'ghost';
  /** Needs a dialog to collect extra input. */
  needsInput: boolean;
  noteRequired?: boolean;
}

export const CASE_ACTIONS: Record<CaseActionKey, ActionDef> = {
  accept: { key: 'accept', label: 'Accept case', from: ['submitted'], to: 'received', permission: PERMISSIONS.CASES_ACCEPT, variant: 'primary', needsInput: true },
  request_correction: { key: 'request_correction', label: 'Request correction', from: ['submitted'], to: 'correction', permission: PERMISSIONS.CASES_ACCEPT, variant: 'secondary', needsInput: true, noteRequired: true },
  resubmit: { key: 'resubmit', label: 'Resubmit case', from: ['correction'], to: 'submitted', permission: PERMISSIONS.CASES_SUBMIT, variant: 'primary', needsInput: true },
  reject: { key: 'reject', label: 'Reject case', from: ['submitted', 'correction'], to: 'rejected', permission: PERMISSIONS.CASES_ACCEPT, variant: 'danger', needsInput: true, noteRequired: true },
  start_review: { key: 'start_review', label: 'Start review', from: ['received'], to: 'review', permission: PERMISSIONS.CASES_ASSIGN, variant: 'secondary', needsInput: false },
  assign: { key: 'assign', label: 'Assign technician', from: ['received', 'review', 'assigned'], to: 'assigned', permission: PERMISSIONS.CASES_ASSIGN, variant: 'primary', needsInput: true },
  start_production: { key: 'start_production', label: 'Start production', from: ['assigned'], to: 'in_production', permission: PERMISSIONS.CASES_UPDATE_STATUS, variant: 'primary', needsInput: false },
  submit_qc: { key: 'submit_qc', label: 'Submit for QC', from: ['in_production'], to: 'quality_control', permission: PERMISSIONS.CASES_UPDATE_STATUS, variant: 'primary', needsInput: true },
  qc_pass: { key: 'qc_pass', label: 'Pass QC', from: ['quality_control'], to: 'ready', permission: PERMISSIONS.QC_PERFORM, variant: 'primary', needsInput: true },
  qc_fail: { key: 'qc_fail', label: 'Fail QC', from: ['quality_control'], to: 'rework', permission: PERMISSIONS.QC_PERFORM, variant: 'danger', needsInput: true, noteRequired: true },
  start_rework: { key: 'start_rework', label: 'Start rework', from: ['rework'], to: 'in_production', permission: PERMISSIONS.CASES_UPDATE_STATUS, variant: 'primary', needsInput: false },
  dispatch: { key: 'dispatch', label: 'Dispatch', from: ['ready'], to: 'out_for_delivery', permission: PERMISSIONS.DELIVERY_MANAGE, variant: 'secondary', needsInput: true },
  deliver: { key: 'deliver', label: 'Mark delivered', from: ['ready', 'out_for_delivery'], to: 'delivered', permission: PERMISSIONS.DELIVERY_MANAGE, variant: 'primary', needsInput: true },
  confirm_receipt: { key: 'confirm_receipt', label: 'Confirm received', from: ['delivered'], to: 'completed', permission: PERMISSIONS.CASES_CONFIRM_RECEIPT, variant: 'primary', needsInput: false },
  cancel: { key: 'cancel', label: 'Cancel case', from: ['submitted', 'correction', 'received', 'review', 'assigned', 'in_production', 'rework', 'quality_control', 'ready'], to: 'cancelled', permission: PERMISSIONS.CASES_CANCEL, variant: 'danger', needsInput: true, noteRequired: true },
};

const TECH_ACTIONS: CaseActionKey[] = ['start_production', 'submit_qc', 'start_rework'];
const CLIENT_ACTIONS: CaseActionKey[] = ['resubmit', 'confirm_receipt'];

export interface Actor {
  user: Pick<User, 'id' | 'role' | 'clinicId' | 'technicianId'>;
  permissions: readonly string[];
}

type CaseRef = Pick<LabCase, 'status' | 'technicianId' | 'clinicId'>;

/** Whether the actor may see this case at all (row-level scope). */
export function canViewCase(actor: Actor, c: Pick<LabCase, 'technicianId' | 'clinicId'>) {
  if (!hasPermission(actor.permissions, PERMISSIONS.CASES_VIEW)) return false;
  if (hasPermission(actor.permissions, PERMISSIONS.CASES_VIEW_ALL)) return true;
  if (actor.user.clinicId) return c.clinicId === actor.user.clinicId;
  if (actor.user.technicianId) return c.technicianId === actor.user.technicianId;
  return false;
}

/**
 * Single source of truth for "may this actor run this action on this case".
 * The mock API enforces it server-side; the UI uses it to show buttons.
 */
export function canPerformAction(action: CaseActionKey, c: CaseRef, actor: Actor): boolean {
  const def = CASE_ACTIONS[action];
  if (!def.from.includes(c.status)) return false;
  if (!hasPermission(actor.permissions, def.permission)) return false;
  if (!canViewCase(actor, c)) return false;

  // Production steps: only the assigned technician, unless the actor can assign (lab manager / admin).
  if (TECH_ACTIONS.includes(action) && !hasPermission(actor.permissions, PERMISSIONS.CASES_ASSIGN)) {
    if (!actor.user.technicianId || actor.user.technicianId !== c.technicianId) return false;
  }
  // Client-portal steps: only the owning clinic.
  if (CLIENT_ACTIONS.includes(action) && actor.user.clinicId && actor.user.clinicId !== c.clinicId) return false;
  return true;
}

export function availableActions(c: CaseRef, actor: Actor): ActionDef[] {
  return (Object.keys(CASE_ACTIONS) as CaseActionKey[])
    .filter((k) => canPerformAction(k, c, actor))
    .map((k) => CASE_ACTIONS[k]);
}

/** The single most relevant action for a card / row (first non-cancel action). */
export function primaryAction(c: CaseRef, actor: Actor): ActionDef | null {
  return availableActions(c, actor).find((a) => a.key !== 'cancel' && a.key !== 'reject') ?? null;
}
