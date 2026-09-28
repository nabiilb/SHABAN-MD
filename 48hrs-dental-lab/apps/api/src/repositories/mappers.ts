/**
 * Prisma rows → the shared API shapes (packages/shared/src/models.ts). The
 * include objects here are the only relation sets the API loads, so every
 * response has exactly the fields the web app expects.
 */
import { invoiceStatus, round2 } from '@48hrs/shared/billing';
import { QC_ISSUES } from '@48hrs/shared/schemas';
import type * as M from '@48hrs/shared/types';
import type { Prisma } from '../generated/prisma/client.ts';
import { money } from '../utils/money.ts';
import { iso } from '../utils/dates.ts';

const isoOrNull = (d: Date | null | undefined) => iso(d);
const isoReq = (d: Date) => d.toISOString();

/* ------------------------------- Users -------------------------------- */

export const userInclude = { technician: { select: { id: true } } } satisfies Prisma.UserInclude;
export type UserRow = Prisma.UserGetPayload<{ include: typeof userInclude }>;

export function toUser(u: UserRow): M.User {
  return {
    id: u.id,
    name: u.name,
    email: u.email,
    phone: u.phone,
    role: u.roleKey as M.RoleKey,
    active: u.active,
    clinicId: u.clinicId,
    doctorId: u.doctorId,
    technicianId: u.technician?.id ?? null,
    lastLoginAt: isoOrNull(u.lastLoginAt),
    createdAt: isoReq(u.createdAt),
  };
}

/* ------------------------------ Directory ----------------------------- */

export function toClinic(k: Prisma.ClinicGetPayload<object>): M.Clinic {
  return { id: k.id, name: k.name, contactPerson: k.contactPerson, phone: k.phone, email: k.email, address: k.address, status: k.status, notes: k.notes, createdAt: isoReq(k.createdAt) };
}

export function toDoctor(d: Prisma.DoctorGetPayload<object>): M.Doctor {
  return { id: d.id, name: d.name, clinicId: d.clinicId, phone: d.phone, email: d.email, specialty: d.specialty, status: d.status, createdAt: isoReq(d.createdAt) };
}

export function toPatient(p: Prisma.PatientGetPayload<object>): M.Patient {
  return {
    id: p.id,
    code: p.code,
    name: p.name,
    phone: p.phone,
    email: p.email,
    gender: p.gender,
    dateOfBirth: p.dateOfBirth ? p.dateOfBirth.toISOString().slice(0, 10) : null,
    clinicId: p.clinicId,
    notes: p.notes,
    createdAt: isoReq(p.createdAt),
  };
}

export function toTechnician(t: Prisma.TechnicianGetPayload<object>): M.Technician {
  return { id: t.id, userId: t.userId, name: t.name, email: t.email, phone: t.phone, specialty: t.specialty, active: t.active, createdAt: isoReq(t.createdAt) };
}

export function toService(s: Prisma.LabServiceGetPayload<object>): M.LabService {
  return { id: s.id, name: s.name, caseType: s.caseType, unitMode: s.unitMode, unitPrice: money(s.unitPrice), defaultMaterial: s.defaultMaterial, active: s.active };
}

/* ------------------------------- Finance ------------------------------ */

type InvoiceMoney = Pick<Prisma.InvoiceGetPayload<object>, 'total' | 'amountPaid' | 'dueDate'>;

/** paid / remaining / status are derived here and nowhere else (Total − Paid = Remaining). */
export function invoiceFigures(inv: InvoiceMoney, now: number) {
  const total = money(inv.total);
  const paid = money(inv.amountPaid);
  return { total, paid, remaining: Math.max(0, round2(total - paid)), status: invoiceStatus(total, paid, inv.dueDate, now) };
}

export function toInvoice(inv: Prisma.InvoiceGetPayload<object>, now: number): M.Invoice {
  return {
    id: inv.id,
    invoiceNumber: inv.invoiceNumber,
    caseId: inv.caseId,
    patientId: inv.patientId,
    doctorId: inv.doctorId,
    clinicId: inv.clinicId,
    subtotal: money(inv.subtotal),
    emergencyFee: money(inv.emergencyFee),
    discount: money(inv.discount),
    ...invoiceFigures(inv, now),
    issuedAt: isoReq(inv.issuedAt),
    dueDate: isoReq(inv.dueDate),
  };
}

export const invoiceListInclude = {
  case: { select: { caseNumber: true } },
  patient: { select: { id: true, name: true } },
  doctor: { select: { id: true, name: true } },
  clinic: { select: { id: true, name: true } },
} satisfies Prisma.InvoiceInclude;
export type InvoiceListRow = Prisma.InvoiceGetPayload<{ include: typeof invoiceListInclude }>;

export function toInvoiceListItem(inv: InvoiceListRow, now: number): M.InvoiceListItem {
  return { ...toInvoice(inv, now), caseNumber: inv.case.caseNumber, patient: inv.patient, doctor: inv.doctor, clinic: inv.clinic };
}

export const paymentInclude = { receivedBy: { select: { name: true } } } satisfies Prisma.PaymentInclude;
export type PaymentRow = Prisma.PaymentGetPayload<{ include: typeof paymentInclude }>;

export function toPayment(p: PaymentRow): M.Payment {
  return {
    id: p.id,
    invoiceId: p.invoiceId,
    amount: money(p.amount),
    method: p.method,
    reference: p.reference,
    notes: p.notes,
    receivedById: p.receivedById,
    receivedByName: p.receivedBy.name,
    paidAt: isoReq(p.paidAt),
  };
}

/* -------------------------------- Cases ------------------------------- */

const noteInclude = { orderBy: { createdAt: 'asc' }, include: { author: { select: { name: true } } } } satisfies Prisma.DentalCase$notesArgs;

export const caseListInclude = {
  patient: { select: { id: true, name: true, code: true } },
  doctor: { select: { id: true, name: true } },
  clinic: { select: { id: true, name: true } },
  technician: { select: { id: true, name: true } },
  invoice: { select: { id: true, total: true, amountPaid: true, dueDate: true } },
  notes: noteInclude,
  _count: { select: { attachments: true } },
} satisfies Prisma.DentalCaseInclude;
export type CaseListRow = Prisma.DentalCaseGetPayload<{ include: typeof caseListInclude }>;

type CaseCore = Prisma.DentalCaseGetPayload<{ include: { invoice: { select: { id: true; total: true; amountPaid: true; dueDate: true } }; notes: typeof noteInclude } }>;

export function toLabCase(c: CaseCore, now: number): M.LabCase {
  return {
    id: c.id,
    caseNumber: c.caseNumber,
    patientId: c.patientId,
    doctorId: c.doctorId,
    clinicId: c.clinicId,
    serviceId: c.serviceId,
    caseType: c.caseType,
    restorationType: c.restorationType,
    material: c.material,
    shade: c.shade,
    teeth: c.teeth,
    dentureType: c.dentureType,
    units: c.units,
    unitPrice: money(c.unitPrice),
    emergencyFee: money(c.emergencyFee),
    total: money(c.total),
    priority: c.priority,
    status: c.status,
    technicianId: c.technicianId,
    instructions: c.instructions,
    notes: c.notes.map((n) => ({ id: n.id, text: n.text, authorId: n.authorId, authorName: n.author.name, createdAt: isoReq(n.createdAt) })),
    reworkCount: c.reworkCount,
    submittedAt: isoOrNull(c.submittedAt),
    receivedAt: isoOrNull(c.receivedAt),
    dueAt: isoOrNull(c.dueAt),
    assignedAt: isoOrNull(c.assignedAt),
    productionStartedAt: isoOrNull(c.productionStartedAt),
    productionCompletedAt: isoOrNull(c.productionCompletedAt),
    qcCompletedAt: isoOrNull(c.qcCompletedAt),
    readyAt: isoOrNull(c.readyAt),
    deliveredAt: isoOrNull(c.deliveredAt),
    completedAt: isoOrNull(c.completedAt),
    cancelledAt: isoOrNull(c.cancelledAt),
    invoiceId: c.invoice?.id ?? null,
    paymentStatus: c.invoice ? invoiceFigures(c.invoice, now).status : 'unpaid',
    createdById: c.createdById,
    createdAt: isoReq(c.createdAt),
    updatedAt: isoReq(c.updatedAt),
  };
}

export function toCaseListItem(c: CaseListRow, now: number): M.CaseListItem {
  return {
    ...toLabCase(c, now),
    patient: c.patient,
    doctor: c.doctor,
    clinic: c.clinic,
    technician: c.technician,
    attachmentCount: c._count.attachments,
  };
}

export const attachmentInclude = { uploadedBy: { select: { name: true } } } satisfies Prisma.CaseAttachmentInclude;
type AttachmentRow = Prisma.CaseAttachmentGetPayload<{ include: typeof attachmentInclude }>;

export function toAttachment(a: AttachmentRow): M.CaseAttachment {
  return {
    id: a.id,
    caseId: a.caseId,
    name: a.name,
    mimeType: a.mimeType,
    extension: a.extension,
    size: a.size,
    category: a.category,
    uploadedById: a.uploadedById,
    uploadedByName: a.uploadedBy.name,
    createdAt: isoReq(a.createdAt),
    url: null,
  };
}

export const qualityCheckInclude = { issues: true, checkedBy: { select: { name: true } } } satisfies Prisma.QualityCheckInclude;
type QualityCheckRow = Prisma.QualityCheckGetPayload<{ include: typeof qualityCheckInclude }>;

export function toQualityCheck(q: QualityCheckRow): M.QualityCheck {
  return {
    id: q.id,
    caseId: q.caseId,
    result: q.result,
    reworkRequired: q.reworkRequired,
    issues: q.issues.map((i) => i.issue).sort((a, b) => QC_ISSUES.indexOf(a) - QC_ISSUES.indexOf(b)),
    notes: q.notes,
    checkedById: q.checkedById,
    checkedByName: q.checkedBy.name,
    checkedAt: isoReq(q.checkedAt),
  };
}

export const deliveryInclude = { recordedBy: { select: { name: true } } } satisfies Prisma.DeliveryInclude;
type DeliveryRow = Prisma.DeliveryGetPayload<{ include: typeof deliveryInclude }>;

export function toDelivery(d: DeliveryRow): M.Delivery {
  return {
    id: d.id,
    caseId: d.caseId,
    status: d.status,
    method: d.method,
    courierName: d.courierName ?? undefined,
    deliveredTo: d.deliveredTo ?? undefined,
    receivedBy: d.receivedBy ?? undefined,
    dispatchedAt: isoOrNull(d.dispatchedAt),
    deliveredAt: isoOrNull(d.deliveredAt),
    notes: d.notes ?? undefined,
    recordedById: d.recordedById,
    recordedByName: d.recordedBy.name,
    createdAt: isoReq(d.createdAt),
  };
}

export const caseDetailInclude = {
  patient: true,
  doctor: true,
  clinic: true,
  technician: true,
  service: true,
  invoice: true,
  notes: noteInclude,
  history: { orderBy: { createdAt: 'asc' }, include: { user: { select: { name: true } } } },
  attachments: { orderBy: { createdAt: 'asc' }, include: attachmentInclude },
  qualityChecks: { orderBy: { checkedAt: 'asc' }, include: qualityCheckInclude },
  deliveries: { orderBy: { createdAt: 'asc' }, include: deliveryInclude },
  _count: { select: { attachments: true } },
} satisfies Prisma.DentalCaseInclude;
export type CaseDetailRow = Prisma.DentalCaseGetPayload<{ include: typeof caseDetailInclude }>;

/** Files and money are only included for users allowed to see them. */
export function toCaseDetail(c: CaseDetailRow, now: number, opts: { files: boolean; money: boolean }): M.CaseDetail {
  return {
    ...toLabCase(c, now),
    patient: toPatient(c.patient),
    doctor: toDoctor(c.doctor),
    clinic: toClinic(c.clinic),
    technician: c.technician ? toTechnician(c.technician) : null,
    service: toService(c.service),
    attachmentCount: c._count.attachments,
    history: c.history.map((h) => ({
      id: h.id,
      caseId: h.caseId,
      fromStatus: h.fromStatus,
      toStatus: h.toStatus,
      userId: h.userId,
      userName: h.user.name,
      userRole: h.userRole as M.RoleKey,
      note: h.note ?? undefined,
      createdAt: isoReq(h.createdAt),
    })),
    attachments: opts.files ? c.attachments.map(toAttachment) : [],
    qualityChecks: c.qualityChecks.map(toQualityCheck),
    deliveries: c.deliveries.map(toDelivery),
    invoice: opts.money && c.invoice ? toInvoice(c.invoice, now) : null,
  };
}

/* --------------------------- Notifications ---------------------------- */

export const notificationInclude = { case: { select: { caseNumber: true } } } satisfies Prisma.NotificationInclude;
type NotificationRow = Prisma.NotificationGetPayload<{ include: typeof notificationInclude }>;

export function toNotification(n: NotificationRow): M.AppNotification {
  return {
    id: n.id,
    userId: n.userId,
    type: n.type,
    title: n.title,
    message: n.message,
    caseId: n.caseId,
    caseNumber: n.case?.caseNumber ?? null,
    readAt: isoOrNull(n.readAt),
    createdAt: isoReq(n.createdAt),
  };
}

export function toActivity(a: Prisma.ActivityLogGetPayload<object>): M.ActivityLogEntry {
  return {
    id: a.id,
    userId: a.userId ?? '',
    userName: a.userName,
    action: a.action,
    description: a.description,
    subjectType: a.subjectType as M.ActivityLogEntry['subjectType'],
    subjectId: a.subjectId,
    subjectLabel: a.subjectLabel,
    createdAt: isoReq(a.createdAt),
  };
}
