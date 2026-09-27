import type {
  AttachmentCategory,
  CaseListItem,
  Clinic,
  Delivery,
  Doctor,
  LabCase,
  Patient,
  QualityCheck,
  Technician,
  CasePriority,
  CaseStatus,
  CaseType,
  DeliveryMethod,
  DentureType,
  Gender,
  ID,
  PaymentMethod,
  PaymentStatus,
  QcIssue,
  RecordStatus,
  RoleKey,
} from './models';

export interface PageMeta {
  page: number;
  perPage: number;
  total: number;
  lastPage: number;
}

export interface Paginated<T> {
  data: T[];
  meta: PageMeta;
}

export type SortDir = 'asc' | 'desc';

export interface ListParams {
  page?: number;
  perPage?: number;
  search?: string;
  sort?: string;
  dir?: SortDir;
}

/** Laravel-style validation error payload: { message, errors: { field: [msg] } } */
export interface ValidationErrorBody {
  message: string;
  errors?: Record<string, string[]>;
}

/* ---------------------------- Auth -------------------------------- */

export interface LoginPayload {
  email: string;
  password: string;
}

export interface ForgotPasswordPayload {
  email: string;
}

export interface ResetPasswordPayload {
  token: string;
  email: string;
  password: string;
  passwordConfirmation: string;
}

/* ---------------------------- Cases ------------------------------- */

export type SlaFilter = 'on_track' | 'at_risk' | 'overdue' | 'due_today';

export interface CaseListParams extends ListParams {
  status?: CaseStatus[];
  priority?: CasePriority[];
  technicianId?: ID;
  doctorId?: ID;
  clinicId?: ID;
  patientId?: ID;
  caseType?: CaseType;
  paymentStatus?: PaymentStatus;
  sla?: SlaFilter;
  /** Filter on receivedAt (falls back to createdAt), YYYY-MM-DD. */
  from?: string;
  to?: string;
  /** Only open (not completed/cancelled/rejected) cases. */
  openOnly?: boolean;
}

export interface CreateCasePayload {
  patientId?: ID | null;
  newPatient?: { name: string; code?: string; phone?: string } | null;
  doctorId: ID;
  clinicId: ID;
  serviceId: ID;
  material: string;
  shade: string;
  teeth: number[];
  dentureType?: DentureType | null;
  priority: CasePriority;
  instructions: string;
  /** Reception intake starts the 48-hour clock immediately. */
  receiveNow: boolean;
  /** Optional override of the due date (ISO); defaults to receivedAt + SLA. */
  dueAt?: string | null;
}

export type UpdateCasePayload = Partial<
  Pick<CreateCasePayload, 'material' | 'shade' | 'teeth' | 'priority' | 'instructions' | 'dentureType'>
>;

/** Every status change goes through a named workflow action. */
export type CaseActionKey =
  | 'accept'
  | 'request_correction'
  | 'resubmit'
  | 'reject'
  | 'start_review'
  | 'assign'
  | 'start_production'
  | 'submit_qc'
  | 'qc_pass'
  | 'qc_fail'
  | 'start_rework'
  | 'dispatch'
  | 'deliver'
  | 'confirm_receipt'
  | 'cancel';

export interface CaseActionPayload {
  action: CaseActionKey;
  note?: string;
  technicianId?: ID;
  /** accept: optional up-front payment */
  payment?: { amount: number; method: PaymentMethod; reference?: string } | null;
  /** qc_pass / qc_fail */
  qc?: { issues: QcIssue[]; notes: string; reworkRequired?: boolean };
  /** dispatch / deliver */
  delivery?: {
    method: DeliveryMethod;
    courierName?: string;
    deliveredTo?: string;
    receivedBy?: string;
    notes?: string;
  };
}

export interface UploadAttachmentPayload {
  file: File;
  category: AttachmentCategory;
}

/* ------------------------- Directory ------------------------------ */

export interface ClinicPayload {
  name: string;
  contactPerson: string;
  phone: string;
  email: string;
  address: string;
  status: RecordStatus;
  notes?: string;
}

export interface DoctorPayload {
  name: string;
  clinicId: ID;
  phone: string;
  email: string;
  specialty: string;
  status: RecordStatus;
}

export interface PatientPayload {
  name: string;
  code?: string;
  phone?: string;
  email?: string;
  gender?: Gender | null;
  dateOfBirth?: string | null;
  clinicId?: ID | null;
  notes?: string;
}

export interface TechnicianPayload {
  name: string;
  email: string;
  phone: string;
  specialty: string;
  active: boolean;
}

export interface DirectoryListParams extends ListParams {
  status?: RecordStatus;
  clinicId?: ID;
}

/* -------------------------- Finance ------------------------------- */

export interface InvoiceListParams extends ListParams {
  status?: PaymentStatus;
  clinicId?: ID;
  doctorId?: ID;
  from?: string;
  to?: string;
}

export interface PaymentListParams extends ListParams {
  method?: PaymentMethod;
  clinicId?: ID;
  from?: string;
  to?: string;
}

export interface RecordPaymentPayload {
  amount: number;
  method: PaymentMethod;
  reference?: string;
  notes?: string;
  paidAt?: string;
}

/* --------------------------- Users -------------------------------- */

export interface UserPayload {
  name: string;
  email: string;
  phone?: string;
  role: RoleKey;
  active: boolean;
  clinicId?: ID | null;
  technicianId?: ID | null;
  /** Only on create, or when an admin resets it. */
  password?: string;
}

export interface UserListParams extends ListParams {
  role?: RoleKey;
  active?: boolean;
}

/* ---------------------- Services catalogue ------------------------ */

export interface ServicePayload {
  name: string;
  caseType: CaseType;
  unitMode: 'tooth' | 'denture' | 'arch';
  unitPrice: number;
  defaultMaterial: string;
  active: boolean;
}

/* -------------------------- Reports ------------------------------- */

export interface ReportFilters {
  from: string;
  to: string;
  technicianId?: ID;
  doctorId?: ID;
  clinicId?: ID;
  status?: CaseStatus;
  caseType?: CaseType;
}

export interface ReportSeriesPoint {
  label: string;
  received: number;
  completed: number;
  overdue: number;
}

export interface ReportResult {
  totals: {
    cases: number;
    completed: number;
    open: number;
    overdue: number;
    onTimeRate: number | null;
    avgTurnaroundHours: number | null;
    revenue: number;
    collected: number;
    outstanding: number;
  };
  daily: ReportSeriesPoint[];
  monthly: (ReportSeriesPoint & { revenue: number })[];
  byStatus: { status: CaseStatus; count: number }[];
  byCaseType: { caseType: CaseType; count: number; revenue: number }[];
  technicians: {
    technicianId: ID;
    name: string;
    assigned: number;
    completed: number;
    onTimeRate: number | null;
    avgProductionHours: number | null;
    qcFailures: number;
  }[];
  clinics: {
    clinicId: ID;
    name: string;
    cases: number;
    completed: number;
    revenue: number;
    outstanding: number;
  }[];
  stages: { stage: string; avgHours: number | null; samples: number }[];
}

/* ------------------------- Dashboard ------------------------------ */

export interface DashboardSummary {
  activeCases: number;
  newToday: number;
  dueToday: number;
  overdue: number;
  completed: number;
  completedToday: number;
  inProduction: number;
  pendingQc: number;
  readyForDelivery: number;
  awaitingAcceptance: number;
  revenueMonth: number | null;
  outstanding: number | null;
  performance: {
    onTime: number;
    atRisk: number;
    overdue: number;
    onTimeRate: number | null;
    avgCompletionHours: number | null;
  };
  last14Days: { date: string; received: number; delivered: number }[];
  statusBreakdown: { status: CaseStatus; count: number }[];
  revenueByMonth: { month: string; invoiced: number; collected: number }[] | null;
}

/* -------------------------- Search -------------------------------- */

export interface SearchResult {
  type: 'case' | 'patient' | 'doctor' | 'clinic' | 'invoice';
  id: ID;
  title: string;
  subtitle: string;
  href: string;
}

/* ---------------------- Directory details ------------------------- */

export interface RelationStats {
  totalCases: number;
  activeCases: number;
  completedCases: number;
  overdueCases: number;
  outstanding: number;
  billed: number;
}

export interface PatientListItem extends Patient {
  clinicName?: string | null;
  caseCount: number;
  lastCaseAt?: string | null;
}

export interface PatientDetail extends PatientListItem {
  stats: RelationStats;
  recentCases: CaseListItem[];
}

export interface DoctorListItem extends Doctor {
  clinicName: string;
  caseCount: number;
  activeCases: number;
}

export interface DoctorDetail extends DoctorListItem {
  clinic: Clinic;
  stats: RelationStats;
  recentCases: CaseListItem[];
}

export interface ClinicListItem extends Clinic {
  doctorCount: number;
  caseCount: number;
  activeCases: number;
  outstanding: number;
}

export interface ClinicDetail extends ClinicListItem {
  doctors: Doctor[];
  stats: RelationStats;
  recentCases: CaseListItem[];
}

export interface TechnicianListItem extends Technician {
  activeCases: number;
  completedCases: number;
  dueToday: number;
  overdue: number;
  onTimeRate: number | null;
}

export interface TechnicianDetail extends TechnicianListItem {
  qcPending: number;
  qcFailures: number;
  avgProductionHours: number | null;
  activeCaseList: CaseListItem[];
  recentCompleted: CaseListItem[];
}

export interface QualityCheckListItem extends QualityCheck {
  caseNumber: string;
  patientName: string;
  clinicName: string;
  technicianName: string | null;
  caseStatus: LabCase['status'];
}

export interface DeliveryListItem extends Delivery {
  caseNumber: string;
  patientName: string;
  clinicName: string;
}

export interface NavCounts {
  awaitingAcceptance: number;
  pendingAssignment: number;
  inProduction: number;
  pendingQc: number;
  readyForDelivery: number;
  overdue: number;
  unreadNotifications: number;
}

export interface ForgotPasswordResult {
  message: string;
  /** Only returned by the mock API, which cannot send e-mail. */
  devResetUrl?: string;
}
