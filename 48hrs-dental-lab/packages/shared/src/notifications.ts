/**
 * Notification wording, recipients and the deadline-alert rule, shared by the
 * API (and its deadline worker) and the mock backend.
 */
import type { LabCase, NotificationType, RoleKey } from './types';
import { getSlaInfo, HOUR_MS, type SlaConfig } from './sla';
import { IN_LAB_STATUSES } from './workflow';
import { PERMISSIONS } from './permissions';

export interface NotificationContent {
  type: NotificationType;
  title: string;
  message: string;
}

type CaseRef = Pick<LabCase, 'caseNumber'>;

export const notify = {
  caseReceived: (c: CaseRef): NotificationContent => ({ type: 'case_received', title: 'New case received', message: `${c.caseNumber} is waiting for review and assignment.` }),
  caseSubmitted: (c: CaseRef, clinicName: string): NotificationContent => ({ type: 'case_submitted', title: 'New case submitted', message: `${c.caseNumber} was submitted by ${clinicName}.` }),
  caseAccepted: (c: CaseRef): NotificationContent => ({ type: 'case_received', title: 'Case accepted', message: `${c.caseNumber} was accepted and is awaiting technician assignment.` }),
  correctionRequested: (c: CaseRef, note: string): NotificationContent => ({ type: 'correction_requested', title: 'Correction requested', message: `${c.caseNumber}: ${note}` }),
  caseResubmitted: (c: CaseRef, clinicName: string): NotificationContent => ({ type: 'case_submitted', title: 'Case resubmitted', message: `${c.caseNumber} was resubmitted by ${clinicName} after correction.` }),
  caseRejected: (c: CaseRef, note: string): NotificationContent => ({ type: 'correction_requested', title: 'Case rejected', message: `${c.caseNumber} was rejected: ${note}` }),
  caseAssigned: (c: CaseRef & Pick<LabCase, 'restorationType'>, hoursLeft: number | null): NotificationContent => ({
    type: 'case_assigned',
    title: 'New case assigned to you',
    message: `${c.caseNumber} — ${c.restorationType}${hoursLeft !== null ? `, ${hoursLeft}h remaining` : ''}.`,
  }),
  qcRequired: (c: CaseRef): NotificationContent => ({ type: 'qc_required', title: 'Quality control required', message: `${c.caseNumber} is waiting for inspection.` }),
  qcFailed: (c: CaseRef, notes: string): NotificationContent => ({ type: 'qc_failed', title: 'QC failed — rework required', message: `${c.caseNumber} was returned: ${notes}` }),
  caseReady: (c: CaseRef): NotificationContent => ({ type: 'case_ready', title: 'Case ready for delivery', message: `${c.caseNumber} passed quality control.` }),
  caseDispatched: (c: CaseRef): NotificationContent => ({ type: 'case_dispatched', title: 'Case on its way', message: `${c.caseNumber} is out for delivery.` }),
  caseDelivered: (c: CaseRef, deliveredTo: string): NotificationContent => ({ type: 'case_delivered', title: 'Case delivered', message: `${c.caseNumber} was delivered to ${deliveredTo}.` }),
  paymentReceived: (amount: number, invoiceNumber: string): NotificationContent => ({ type: 'payment_received', title: 'Payment received', message: `${amount.toFixed(2)} received on ${invoiceNumber}.` }),
  caseOverdue: (c: CaseRef, slaHours: number): NotificationContent => ({ type: 'case_overdue', title: 'Case overdue', message: `${c.caseNumber} has exceeded the ${slaHours}-hour deadline.` }),
  deadlineApproaching: (c: CaseRef, hoursLeft: number): NotificationContent => ({
    type: 'deadline_approaching',
    title: 'Deadline approaching',
    message: `${c.caseNumber} has about ${hoursLeft} hour${hoursLeft === 1 ? '' : 's'} remaining.`,
  }),
};

/** "Active users of these roles holding this permission" — how staff recipients are chosen. */
export interface RecipientRule {
  permission: string;
  roles: RoleKey[];
}

export const RECIPIENTS = {
  /** Lab managers who assign new work. */
  assigners: { permission: PERMISSIONS.CASES_ASSIGN, roles: ['lab_manager'] },
  /** Deadline alerts go to managers and admins who can reassign. */
  deadlineWatchers: { permission: PERMISSIONS.CASES_ASSIGN, roles: ['lab_manager', 'admin'] },
  /** Reception accepts portal submissions. */
  intake: { permission: PERMISSIONS.CASES_ACCEPT, roles: ['reception'] },
  intakeAndAdmin: { permission: PERMISSIONS.CASES_ACCEPT, roles: ['reception', 'admin'] },
  inspectors: { permission: PERMISSIONS.QC_PERFORM, roles: ['qc', 'lab_manager'] },
  dispatchers: { permission: PERMISSIONS.DELIVERY_MANAGE, roles: ['delivery', 'reception'] },
  finance: { permission: PERMISSIONS.REPORTS_FINANCIAL, roles: ['admin', 'super_admin'] },
} satisfies Record<string, RecipientRule>;

export interface DeadlineFlags {
  atRisk?: boolean;
  overdue?: boolean;
}

export type DeadlineAlert =
  | { kind: 'overdue'; content: NotificationContent }
  | { kind: 'at_risk'; content: NotificationContent };

/**
 * Which deadline alert (if any) a case needs right now, given the alerts already
 * raised for it. Each alert fires once per case; overdue implies at-risk.
 */
export function deadlineAlert(
  c: Pick<LabCase, 'caseNumber' | 'status' | 'receivedAt' | 'dueAt' | 'deliveredAt'>,
  flags: DeadlineFlags,
  now: number,
  sla: SlaConfig,
): DeadlineAlert | null {
  if (!IN_LAB_STATUSES.includes(c.status) || !c.dueAt) return null;
  const info = getSlaInfo(c, now, sla);
  if (info.state === 'overdue' && !flags.overdue) return { kind: 'overdue', content: notify.caseOverdue(c, sla.slaHours) };
  if ((info.state === 'at_risk' || info.state === 'critical') && !flags.atRisk) {
    const hours = Math.max(1, Math.round((info.remainingMs ?? 0) / HOUR_MS));
    return { kind: 'at_risk', content: notify.deadlineApproaching(c, hours) };
  }
  return null;
}
