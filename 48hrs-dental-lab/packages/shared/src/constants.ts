import type {
  AttachmentCategory,
  CasePriority,
  CaseType,
  DeliveryMethod,
  DeliveryStatus,
  LabSettings,
  NotificationType,
  PaymentMethod,
  PaymentStatus,
  QcIssue,
} from './models';
import type { Tone } from './workflow';

export const SHADES = ['A1', 'A2', 'A3', 'A3.5', 'B1', 'B2', 'C1', 'C2', 'D2', 'BL1', 'Clear'] as const;

export const MATERIALS = [
  'Zirconia',
  'Lithium disilicate (E-Max)',
  'Porcelain fused to metal',
  'Composite',
  'Acrylic',
  'Titanium abutment + zirconia',
  'Hard-soft EVA',
] as const;

export const CASE_TYPE_LABELS: Record<CaseType, string> = {
  crown: 'Crown',
  bridge: 'Bridge',
  veneer: 'Veneer',
  implant: 'Implant',
  denture: 'Denture',
  appliance: 'Appliance',
};

export const PRIORITY_META: Record<CasePriority, { label: string; tone: Tone }> = {
  normal: { label: 'Normal', tone: 'neutral' },
  high: { label: 'High', tone: 'warning' },
  urgent: { label: 'Emergency', tone: 'danger' },
};

export const PAYMENT_STATUS_META: Record<PaymentStatus, { label: string; tone: Tone }> = {
  unpaid: { label: 'Unpaid', tone: 'neutral' },
  partial: { label: 'Partial', tone: 'warning' },
  paid: { label: 'Paid', tone: 'success' },
  overdue: { label: 'Overdue', tone: 'danger' },
};

export const PAYMENT_METHOD_LABELS: Record<PaymentMethod, string> = {
  cash: 'Cash',
  bank_transfer: 'Bank transfer',
  mobile_money: 'Mobile money',
  card: 'Card',
  other: 'Other',
};

export const DELIVERY_METHOD_LABELS: Record<DeliveryMethod, string> = {
  clinic_pickup: 'Clinic pickup',
  lab_courier: 'Lab courier',
  third_party: 'Third-party delivery',
};

export const DELIVERY_STATUS_LABELS: Record<DeliveryStatus, string> = {
  ready: 'Ready',
  out_for_delivery: 'Out for delivery',
  delivered: 'Delivered',
};

export const ATTACHMENT_CATEGORY_LABELS: Record<AttachmentCategory, string> = {
  photo: 'Photo',
  scan: 'Dental scan (STL)',
  xray: 'X-Ray',
  prescription: 'Prescription',
  document: 'Document',
  production: 'Production',
  qc: 'QC',
};

export const QC_ISSUE_LABELS: Record<QcIssue, string> = {
  fit: 'Fit / seating',
  margins: 'Margins',
  occlusion: 'Occlusion',
  contacts: 'Contacts',
  shade: 'Shade mismatch',
  contour: 'Contour / anatomy',
  finish: 'Surface finish / glaze',
  other: 'Other',
};

export const NOTIFICATION_TONE: Record<NotificationType, Tone> = {
  case_submitted: 'info',
  case_received: 'info',
  case_assigned: 'info',
  deadline_approaching: 'warning',
  case_overdue: 'danger',
  qc_required: 'warning',
  qc_failed: 'danger',
  case_ready: 'success',
  case_dispatched: 'info',
  case_delivered: 'success',
  correction_requested: 'warning',
  payment_received: 'success',
};

/** File rules from the prototype: JPG · JPEG · PNG · PDF · STL, max 50 MB. */
export const ALLOWED_FILE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'stl', 'ply', 'obj', 'dcm', 'doc', 'docx'] as const;
export const PREVIEWABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'] as const;
export const MAX_FILE_MB = 50;

export function extensionOf(name: string) {
  const parts = name.split('.');
  return parts.length > 1 ? (parts.pop() ?? '').toLowerCase() : '';
}

export function categoryForExtension(ext: string): AttachmentCategory {
  if (['stl', 'ply', 'obj'].includes(ext)) return 'scan';
  if (ext === 'dcm') return 'xray';
  if (ext === 'pdf') return 'prescription';
  if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) return 'photo';
  return 'document';
}

/** Returns an error message, or null when the file is acceptable. */
export function validateFile(file: { name: string; size: number }): string | null {
  const ext = extensionOf(file.name);
  if (!(ALLOWED_FILE_EXTENSIONS as readonly string[]).includes(ext)) return `.${ext || 'unknown'} files are not accepted.`;
  if (file.size > MAX_FILE_MB * 1_048_576) return `Larger than the ${MAX_FILE_MB} MB limit.`;
  return null;
}

/** Settings used until an administrator saves their own (Settings → Lab profile & SLA). */
export const DEFAULT_LAB_SETTINGS: LabSettings = {
  labName: '48HRS Dental Lab',
  phone: '',
  email: '',
  address: '',
  currency: 'USD',
  slaHours: 48,
  atRiskHours: 12,
  criticalHours: 4,
  emergencyFeePerUnit: 5,
  invoiceDueDays: 14,
};
