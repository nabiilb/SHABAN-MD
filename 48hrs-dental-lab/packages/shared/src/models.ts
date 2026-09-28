/**
 * Core entities — exactly what the REST API (apps/api) returns and the mock
 * backend mirrors. IDs are strings; timestamps are ISO-8601 strings in UTC.
 */

export type ID = string;
export type ISODate = string;

/* ------------------------------------------------------------------ */
/* Access control                                                      */
/* ------------------------------------------------------------------ */

export type RoleKey =
  | 'super_admin'
  | 'admin'
  | 'lab_manager'
  | 'reception'
  | 'technician'
  | 'qc'
  | 'delivery'
  | 'client';

export interface Role {
  key: RoleKey;
  name: string;
  description: string;
  /** Super Admin permissions cannot be edited. */
  locked: boolean;
  permissions: string[];
}

export interface Permission {
  key: string;
  label: string;
  group: string;
  description?: string;
}

export interface User {
  id: ID;
  name: string;
  email: string;
  phone?: string;
  role: RoleKey;
  active: boolean;
  /** Set for client-portal users: the clinic whose cases they may see. */
  clinicId?: ID | null;
  /** Set for doctors who log into the client portal. */
  doctorId?: ID | null;
  /** Set for technician users: their technician profile. */
  technicianId?: ID | null;
  lastLoginAt?: ISODate | null;
  createdAt: ISODate;
}

export interface AuthSession {
  /** Mock backend only. The Node API keeps its tokens in HTTP-only cookies. */
  token?: string;
  /** When the session ends (absolute); access tokens are refreshed silently until then. */
  expiresAt: ISODate;
  user: User;
  permissions: string[];
}

/* ------------------------------------------------------------------ */
/* Directory                                                           */
/* ------------------------------------------------------------------ */

export type RecordStatus = 'active' | 'inactive';

export interface Clinic {
  id: ID;
  name: string;
  contactPerson: string;
  phone: string;
  email: string;
  address: string;
  status: RecordStatus;
  notes?: string;
  createdAt: ISODate;
}

export interface Doctor {
  id: ID;
  name: string;
  clinicId: ID;
  phone: string;
  email: string;
  specialty: string;
  status: RecordStatus;
  createdAt: ISODate;
}

export type Gender = 'male' | 'female';

export interface Patient {
  id: ID;
  /** Human-readable reference, e.g. PT-1024. */
  code: string;
  name: string;
  phone?: string;
  email?: string;
  gender?: Gender | null;
  dateOfBirth?: string | null;
  clinicId?: ID | null;
  notes?: string;
  createdAt: ISODate;
}

export interface Technician {
  id: ID;
  userId?: ID | null;
  name: string;
  email: string;
  phone: string;
  specialty: string;
  active: boolean;
  createdAt: ISODate;
}

/* ------------------------------------------------------------------ */
/* Catalogue                                                           */
/* ------------------------------------------------------------------ */

/** How units are counted for a service (from the prototype's price list). */
export type ServiceUnitMode = 'tooth' | 'denture' | 'arch';

export type CaseType = 'crown' | 'bridge' | 'veneer' | 'implant' | 'denture' | 'appliance';

export interface LabService {
  id: ID;
  name: string;
  caseType: CaseType;
  unitMode: ServiceUnitMode;
  unitPrice: number;
  defaultMaterial: string;
  active: boolean;
}

/* ------------------------------------------------------------------ */
/* Cases                                                               */
/* ------------------------------------------------------------------ */

export type CaseStatus =
  | 'submitted'
  | 'correction'
  | 'received'
  | 'review'
  | 'assigned'
  | 'in_production'
  | 'rework'
  | 'quality_control'
  | 'ready'
  | 'out_for_delivery'
  | 'delivered'
  | 'completed'
  | 'cancelled'
  | 'rejected';

export type CasePriority = 'normal' | 'high' | 'urgent';

export type PaymentStatus = 'unpaid' | 'partial' | 'paid' | 'overdue';

export type DentureType = 'full_upper' | 'full_lower' | 'upper_lower' | 'partial';

export interface CaseNote {
  id: ID;
  text: string;
  authorId: ID;
  authorName: string;
  createdAt: ISODate;
}

export interface LabCase {
  id: ID;
  /** Display number, e.g. DL-2026-00126. */
  caseNumber: string;
  patientId: ID;
  doctorId: ID;
  clinicId: ID;
  serviceId: ID;
  caseType: CaseType;
  restorationType: string;
  material: string;
  shade: string;
  teeth: number[];
  dentureType?: DentureType | null;
  units: number;
  unitPrice: number;
  emergencyFee: number;
  total: number;
  priority: CasePriority;
  status: CaseStatus;
  technicianId?: ID | null;
  instructions: string;
  notes: CaseNote[];
  reworkCount: number;
  /** Client portal submission time (null for cases entered at reception). */
  submittedAt?: ISODate | null;
  /** When the lab accepted the case — the 48-hour clock starts here. */
  receivedAt?: ISODate | null;
  /** receivedAt + SLA hours. */
  dueAt?: ISODate | null;
  assignedAt?: ISODate | null;
  productionStartedAt?: ISODate | null;
  productionCompletedAt?: ISODate | null;
  qcCompletedAt?: ISODate | null;
  readyAt?: ISODate | null;
  deliveredAt?: ISODate | null;
  completedAt?: ISODate | null;
  cancelledAt?: ISODate | null;
  invoiceId?: ID | null;
  paymentStatus: PaymentStatus;
  createdById: ID;
  createdAt: ISODate;
  updatedAt: ISODate;
}

/** Case with its relations resolved — what list/detail endpoints return. */
export interface CaseListItem extends LabCase {
  patient: Pick<Patient, 'id' | 'name' | 'code'>;
  doctor: Pick<Doctor, 'id' | 'name'>;
  clinic: Pick<Clinic, 'id' | 'name'>;
  technician?: Pick<Technician, 'id' | 'name'> | null;
  attachmentCount: number;
}

export interface CaseStatusHistory {
  id: ID;
  caseId: ID;
  fromStatus: CaseStatus | null;
  toStatus: CaseStatus;
  userId: ID;
  userName: string;
  userRole: RoleKey;
  note?: string;
  createdAt: ISODate;
}

export type AttachmentCategory = 'photo' | 'scan' | 'xray' | 'prescription' | 'document' | 'production' | 'qc';

export interface CaseAttachment {
  id: ID;
  caseId: ID;
  name: string;
  mimeType: string;
  extension: string;
  size: number;
  category: AttachmentCategory;
  uploadedById: ID;
  uploadedByName: string;
  createdAt: ISODate;
  /** Server-provided URL (signed URL in production). */
  url?: string | null;
}

export type QcIssue = 'fit' | 'margins' | 'occlusion' | 'contacts' | 'shade' | 'contour' | 'finish' | 'other';

export interface QualityCheck {
  id: ID;
  caseId: ID;
  result: 'passed' | 'failed';
  reworkRequired: boolean;
  issues: QcIssue[];
  notes: string;
  checkedById: ID;
  checkedByName: string;
  checkedAt: ISODate;
}

export type DeliveryMethod = 'clinic_pickup' | 'lab_courier' | 'third_party';
export type DeliveryStatus = 'ready' | 'out_for_delivery' | 'delivered';

export interface Delivery {
  id: ID;
  caseId: ID;
  status: DeliveryStatus;
  method: DeliveryMethod;
  courierName?: string;
  deliveredTo?: string;
  receivedBy?: string;
  dispatchedAt?: ISODate | null;
  deliveredAt?: ISODate | null;
  notes?: string;
  recordedById: ID;
  recordedByName: string;
  createdAt: ISODate;
}

export interface CaseDetail extends CaseListItem {
  patient: Patient;
  doctor: Doctor;
  clinic: Clinic;
  technician?: Technician | null;
  service: LabService;
  history: CaseStatusHistory[];
  attachments: CaseAttachment[];
  qualityChecks: QualityCheck[];
  deliveries: Delivery[];
  invoice?: Invoice | null;
}

/* ------------------------------------------------------------------ */
/* Finance                                                             */
/* ------------------------------------------------------------------ */

export type PaymentMethod = 'cash' | 'bank_transfer' | 'mobile_money' | 'card' | 'other';

export interface Payment {
  id: ID;
  invoiceId: ID;
  amount: number;
  method: PaymentMethod;
  reference?: string;
  notes?: string;
  receivedById: ID;
  receivedByName: string;
  paidAt: ISODate;
}

export interface Invoice {
  id: ID;
  invoiceNumber: string;
  caseId: ID;
  patientId: ID;
  doctorId: ID;
  clinicId: ID;
  subtotal: number;
  emergencyFee: number;
  discount: number;
  total: number;
  paid: number;
  remaining: number;
  status: PaymentStatus;
  issuedAt: ISODate;
  dueDate: ISODate;
}

/** Invoice as stored: paid / remaining / status are always derived from its payments. */
export type InvoiceRecord = Omit<Invoice, 'paid' | 'remaining' | 'status'>;

export interface InvoiceListItem extends Invoice {
  caseNumber: string;
  patient: Pick<Patient, 'id' | 'name'>;
  doctor: Pick<Doctor, 'id' | 'name'>;
  clinic: Pick<Clinic, 'id' | 'name'>;
}

export interface InvoiceDetail extends InvoiceListItem {
  payments: Payment[];
  lineItems: { description: string; quantity: number; unitPrice: number; amount: number }[];
}

export interface PaymentListItem extends Payment {
  invoiceNumber: string;
  caseId: ID;
  caseNumber: string;
  clinic: Pick<Clinic, 'id' | 'name'>;
}

/* ------------------------------------------------------------------ */
/* Notifications & audit                                               */
/* ------------------------------------------------------------------ */

export type NotificationType =
  | 'case_submitted'
  | 'case_received'
  | 'case_assigned'
  | 'deadline_approaching'
  | 'case_overdue'
  | 'qc_required'
  | 'qc_failed'
  | 'case_ready'
  | 'case_dispatched'
  | 'case_delivered'
  | 'correction_requested'
  | 'payment_received';

export interface AppNotification {
  id: ID;
  userId: ID;
  type: NotificationType;
  title: string;
  message: string;
  caseId?: ID | null;
  caseNumber?: string | null;
  readAt?: ISODate | null;
  createdAt: ISODate;
}

export interface ActivityLogEntry {
  id: ID;
  userId: ID;
  userName: string;
  action: string;
  description: string;
  subjectType: 'case' | 'invoice' | 'payment' | 'user' | 'role' | 'service' | 'settings' | 'patient' | 'doctor' | 'clinic' | 'technician' | 'auth';
  subjectId?: ID | null;
  subjectLabel?: string | null;
  createdAt: ISODate;
}

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

export interface LabSettings {
  labName: string;
  phone: string;
  email: string;
  address: string;
  currency: string;
  slaHours: number;
  /** Warn when this many hours or fewer remain. */
  atRiskHours: number;
  /** Critical when this many hours or fewer remain. */
  criticalHours: number;
  emergencyFeePerUnit: number;
  invoiceDueDays: number;
}
