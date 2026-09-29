/**
 * Request-body schemas for the REST API. The API validates every body with
 * these (a failure is a 422 keyed by field path); relational rules such as
 * "this doctor belongs to that clinic" are checked by the API services.
 */
import { z } from 'zod';
import type {
  AttachmentCategory,
  CasePriority,
  CaseType,
  DeliveryMethod,
  DentureType,
  Gender,
  PaymentMethod,
  PaymentStatus,
  QcIssue,
  RecordStatus,
  RoleKey,
  ServiceUnitMode,
} from './types';
import { ATTACHMENT_CATEGORY_LABELS, CASE_TYPE_LABELS, DELIVERY_METHOD_LABELS, PAYMENT_METHOD_LABELS, PAYMENT_STATUS_META, PRIORITY_META, QC_ISSUE_LABELS } from './constants';
import { DENTURE_TYPES } from './billing';
import { ROLE_ORDER } from './permissions';
import { ALL_STATUSES } from './workflow';
import { isDay } from './dates';
import { passwordSchema } from './validation';

const keys = <K extends string>(o: Record<K, unknown>) => Object.keys(o) as [K, ...K[]];

export const CASE_TYPES = keys(CASE_TYPE_LABELS) as [CaseType, ...CaseType[]];
export const PRIORITIES = keys(PRIORITY_META) as [CasePriority, ...CasePriority[]];
export const PAYMENT_METHODS = keys(PAYMENT_METHOD_LABELS) as [PaymentMethod, ...PaymentMethod[]];
export const PAYMENT_STATUSES = keys(PAYMENT_STATUS_META) as [PaymentStatus, ...PaymentStatus[]];
export const DELIVERY_METHODS = keys(DELIVERY_METHOD_LABELS) as [DeliveryMethod, ...DeliveryMethod[]];
export const QC_ISSUES = keys(QC_ISSUE_LABELS) as [QcIssue, ...QcIssue[]];
export const ATTACHMENT_CATEGORIES = keys(ATTACHMENT_CATEGORY_LABELS) as [AttachmentCategory, ...AttachmentCategory[]];
export const DENTURE_TYPE_VALUES = DENTURE_TYPES.map((d) => d.value) as [DentureType, ...DentureType[]];
export const ROLE_KEYS = ROLE_ORDER as [RoleKey, ...RoleKey[]];
export const RECORD_STATUSES: [RecordStatus, ...RecordStatus[]] = ['active', 'inactive'];
export const GENDERS: [Gender, ...Gender[]] = ['male', 'female'];
export const SERVICE_UNIT_MODES: [ServiceUnitMode, ...ServiceUnitMode[]] = ['tooth', 'denture', 'arch'];

const PHONE = /^[+\d][\d\s()-]{5,}$/;

/** Required, trimmed text with a friendly message whether the key is missing, null or blank. */
const required = (label: string, max = 120) =>
  z
    .string({ required_error: `${label} is required.`, invalid_type_error: `${label} is required.` })
    .trim()
    .min(1, `${label} is required.`)
    .max(max, `${label} must be ${max} characters or fewer.`);

const text = (max = 500) => z.string().trim().max(max, `Keep this under ${max} characters.`);
const optionalText = (max = 500) => text(max).optional().default('');
const id = (label: string) => required(label, 64);
const optionalId = z.string().trim().max(64).nullish().transform((v) => v || null);
const email = z.string({ required_error: 'Email is required.' }).trim().toLowerCase().min(1, 'Email is required.').email('Enter a valid email address.');
const optionalEmail = z.union([z.literal(''), z.string().trim().toLowerCase().email('Enter a valid email address.')]).optional().default('');
const phone = required('Phone', 40).regex(PHONE, 'Enter a valid phone number.');
const optionalPhone = z.union([z.literal(''), z.string().trim().regex(PHONE, 'Enter a valid phone number.')]).optional().default('');
const isoDateTime = z.string().datetime({ offset: true, message: 'Use an ISO-8601 date and time.' });
/** A real calendar day (1990-13-40 matches the pattern but is not a date). */
const day = z.string().refine(isDay, 'Use a date in YYYY-MM-DD format.');
const note = text(1000).optional();
const choice = <T extends string>(values: [T, ...T[]], message: string) => z.enum(values, { errorMap: () => ({ message }) });

/* ------------------------------- Auth -------------------------------- */

export const loginSchema = z.object({
  email,
  password: z.string({ required_error: 'Password is required.' }).min(1, 'Password is required.').max(200),
});

export const forgotPasswordSchema = z.object({ email });

export const resetPasswordSchema = z
  .object({
    token: required('Reset token', 200),
    email,
    password: passwordSchema,
    passwordConfirmation: z.string({ required_error: 'Confirm the password.' }),
  })
  .refine((v) => v.password === v.passwordConfirmation, { path: ['passwordConfirmation'], message: 'Passwords do not match.' });

/* ------------------------------- Cases ------------------------------- */

export const createCaseSchema = z.object({
  patientId: optionalId,
  newPatient: z
    .object({ name: required('Patient name'), code: text(40).optional(), phone: optionalPhone })
    .nullish()
    .transform((v) => v ?? null),
  doctorId: id('Doctor'),
  /** Ignored for clinic-portal users (their own clinic is used). */
  clinicId: z.string().trim().max(64).optional().default(''),
  serviceId: id('Service'),
  material: optionalText(120),
  shade: required('Shade', 20),
  teeth: z.array(z.number().int('Teeth must use universal numbering 1–32.').min(1, 'Teeth must use universal numbering 1–32.').max(32, 'Teeth must use universal numbering 1–32.')).max(32).default([]),
  dentureType: choice(DENTURE_TYPE_VALUES, 'Select the denture type.').nullish().transform((v) => v ?? null),
  priority: choice(PRIORITIES, 'Select a priority.'),
  instructions: optionalText(2000),
  receiveNow: z.boolean().default(true),
  dueAt: isoDateTime.nullish().transform((v) => v ?? null),
});
export type CreateCaseBody = z.infer<typeof createCaseSchema>;

export const updateCaseSchema = z
  .object({
    material: text(120),
    shade: required('Shade', 20),
    teeth: createCaseSchema.shape.teeth.removeDefault(),
    priority: choice(PRIORITIES, 'Select a priority.'),
    instructions: text(2000),
    dentureType: choice(DENTURE_TYPE_VALUES, 'Select the denture type.').nullable(),
  })
  .partial();

export const caseNoteSchema = z.object({
  text: z.string({ required_error: 'Write a note first.', invalid_type_error: 'Write a note first.' }).trim().min(1, 'Write a note first.').max(1000, 'Keep notes under 1000 characters.'),
});

export const caseStatusSchema = z.object({
  status: choice(ALL_STATUSES as [(typeof ALL_STATUSES)[number], ...(typeof ALL_STATUSES)[number][]], 'Choose a valid status.'),
  note,
  payment: z
    .object({
      amount: z.coerce.number({ invalid_type_error: 'Enter an amount greater than zero.' }),
      method: choice(PAYMENT_METHODS, 'Choose a payment method.'),
      reference: text(120).optional(),
    })
    .nullish()
    .transform((v) => v ?? null),
});

/** A missing technician is reported by validateActionInput ("Choose a technician."), like every other action rule. */
export const caseAssignSchema = z.object({ technicianId: z.string().trim().max(64).optional().default(''), note });

export const caseQcSchema = z.object({
  result: z.enum(['pass', 'fail'], { errorMap: () => ({ message: 'Result must be pass or fail.' }) }),
  issues: z.array(choice(QC_ISSUES, 'Unknown QC issue.')).max(QC_ISSUES.length).default([]),
  notes: text(1000).default(''),
});

export const caseReworkSchema = z.object({ note });

export const caseDeliverySchema = z.object({
  status: z.enum(['out_for_delivery', 'delivered'], { errorMap: () => ({ message: 'Status must be out_for_delivery or delivered.' }) }),
  method: choice(DELIVERY_METHODS, 'Choose a delivery method.'),
  courierName: text(120).optional(),
  deliveredTo: text(160).optional(),
  receivedBy: text(120).optional(),
  notes: text(1000).optional(),
});

export const attachmentCategorySchema = choice(ATTACHMENT_CATEGORIES, 'Choose a file category.').optional();

/* ----------------------------- Directory ----------------------------- */

export const clinicSchema = z.object({
  name: required('Clinic name'),
  contactPerson: optionalText(120),
  phone,
  email: optionalEmail,
  address: optionalText(300),
  status: choice(RECORD_STATUSES, 'Choose a status.').default('active'),
  notes: optionalText(1000),
});

export const doctorSchema = z.object({
  name: required('Name'),
  clinicId: id('Clinic'),
  phone,
  email: optionalEmail,
  specialty: optionalText(120),
  status: choice(RECORD_STATUSES, 'Choose a status.').default('active'),
});

export const patientSchema = z.object({
  name: required('Patient name'),
  code: text(40).optional(),
  phone: optionalPhone,
  email: optionalEmail,
  gender: choice(GENDERS, 'Choose male or female.').nullish().transform((v) => v ?? null),
  dateOfBirth: z.union([z.literal(''), day]).nullish().transform((v) => v || null),
  clinicId: optionalId,
  notes: optionalText(1000),
});

export const technicianSchema = z.object({
  name: required('Name'),
  email,
  phone,
  specialty: required('Specialty'),
  active: z.boolean().default(true),
});

/* ------------------------------ Finance ------------------------------ */

export const recordPaymentSchema = z.object({
  invoiceId: id('Invoice'),
  amount: z.coerce.number({ invalid_type_error: 'Enter an amount greater than zero.' }),
  method: choice(PAYMENT_METHODS, 'Choose a payment method.'),
  reference: optionalText(120),
  notes: optionalText(500),
  paidAt: isoDateTime.optional(),
});

export const createInvoiceSchema = z.object({ caseId: id('Case') });

/* ---------------------------- Administration ------------------------- */

export const userSchema = z.object({
  name: required('Full name'),
  email,
  phone: optionalPhone,
  role: choice(ROLE_KEYS, 'Choose a role.'),
  active: z.boolean().default(true),
  clinicId: optionalId,
  technicianId: optionalId,
  password: z.union([z.literal(''), passwordSchema]).optional().transform((v) => v || undefined),
});

export const userStatusSchema = z.object({ active: z.boolean({ required_error: 'Choose enabled or disabled.' }) });

export const rolePermissionsSchema = z.object({ permissions: z.array(z.string().max(64)).max(200) });

export const serviceSchema = z.object({
  name: required('Service name'),
  caseType: choice(CASE_TYPES, 'Choose a case type.'),
  unitMode: choice(SERVICE_UNIT_MODES, 'Choose a billing unit.'),
  unitPrice: z.coerce.number({ invalid_type_error: 'Enter a price of 0 or more.' }).min(0, 'Enter a price of 0 or more.').max(100_000, 'Enter a realistic price.'),
  defaultMaterial: required('Default material'),
  active: z.boolean().default(true),
});

const bounded = (label: string, min: number, max: number) =>
  z.coerce.number({ invalid_type_error: `${label} must be between ${min} and ${max}.` }).min(min, `${label} must be between ${min} and ${max}.`).max(max, `${label} must be between ${min} and ${max}.`);

export const settingsSchema = z
  .object({
    labName: required('Lab name'),
    phone: optionalText(40),
    email: optionalEmail,
    address: optionalText(300),
    currency: z.string().trim().regex(/^[A-Z]{3}$/, 'Use a 3-letter ISO currency code, e.g. USD.'),
    slaHours: bounded('Turnaround', 4, 240),
    atRiskHours: bounded('At-risk threshold', 1, 72),
    criticalHours: bounded('Critical threshold', 1, 48),
    emergencyFeePerUnit: bounded('Emergency fee', 0, 1000),
    invoiceDueDays: bounded('Invoice terms', 0, 120),
  })
  .refine((v) => v.criticalHours < v.atRiskHours, { path: ['criticalHours'], message: 'Critical must be lower than the at-risk threshold.' });

/** Zod issues → { "field.path": ["message", …] } (the 422 body). */
export function fieldErrors(error: z.ZodError): Record<string, string[]> {
  const out: Record<string, string[]> = {};
  for (const issue of error.issues) {
    const key = issue.path.join('.') || '_';
    (out[key] ??= []).push(issue.message);
  }
  return out;
}
