import { priceCase } from '@48hrs/shared/billing';
import { caseNumber, patientCode } from '@48hrs/shared/case-keys';
import { notify, RECIPIENTS } from '@48hrs/shared/notifications';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import type { CreateCaseBody } from '@48hrs/shared/schemas';
import type { CaseDetail, CaseListItem, CaseStatus, NavCounts, Paginated, UpdateCasePayload } from '@48hrs/shared/types';
import { STATUS_META } from '@48hrs/shared/workflow';
import { forbidden, notFound, throwIfErrors, unprocessable, validation, type FieldErrors } from '../lib/errors.ts';
import { prisma, type Tx } from '../lib/prisma.ts';
import { removeObject } from '../lib/storage.ts';
import { logger } from '../lib/logger.ts';
import { notifyUsers, recipients } from '../notifications/dispatcher.ts';
import { assertCanViewCase, can, caseScope } from '../policies/case-policy.ts';
import { logActivity } from '../repositories/activity-repository.ts';
import { caseListWhere, caseOrderBy, caseRepository, type CaseFilters, type CaseSort } from '../repositories/case-repository.ts';
import { toCaseDetail, toCaseListItem, invoiceFigures, type CaseDetailRow } from '../repositories/mappers.ts';
import { nextSequence, SEQUENCES } from '../repositories/sequence-repository.ts';
import { readLabSettings, slaConfigOf } from '../repositories/settings-repository.ts';
import type { AuthContext } from '../types/auth.ts';
import { labYear } from '../utils/dates.ts';
import { paginate, type PageRequest } from '../utils/query.ts';
import { caseSlaService } from './case-sla-service.ts';
import { issueInvoice } from './invoice-issuer.ts';

/** What a caller may see of a case: files and money depend on their permissions. */
export function presentCase(auth: AuthContext, row: CaseDetailRow, now = Date.now()): CaseDetail {
  return toCaseDetail(row, now, {
    files: can(auth, PERMISSIONS.FILES_VIEW),
    money: can(auth, [PERMISSIONS.INVOICES_VIEW, PERMISSIONS.PAYMENTS_VIEW], 'any'),
  });
}

export async function recordHistory(tx: Tx, auth: AuthContext, caseId: string, from: CaseStatus | null, to: CaseStatus, note?: string | null) {
  await tx.caseStatusHistory.create({ data: { caseId, fromStatus: from, toStatus: to, userId: auth.user.id, userRole: auth.user.role, note: note?.trim() || null } });
}

export const caseService = {
  async list(auth: AuthContext, filters: CaseFilters, page: PageRequest, sort: CaseSort, dir: 'asc' | 'desc'): Promise<Paginated<CaseListItem>> {
    const now = Date.now();
    const sla = await caseSlaService.config(prisma);
    const where = caseListWhere(caseScope(auth), filters, sla, now);
    const total = await caseRepository.count(prisma, where);
    const { skip, take, meta } = paginate(page, total);
    const rows = await caseRepository.list(prisma, where, caseOrderBy(sort, dir), skip, take);
    return { data: rows.map((r) => toCaseListItem(r, now)), meta };
  },

  /** Sidebar badges: counts over the cases this user may see, plus unread notifications. */
  async counts(auth: AuthContext): Promise<NavCounts> {
    const scope = caseScope(auth);
    const [byStatus, overdue, unread] = await Promise.all([
      caseRepository.statusCounts(prisma, scope),
      caseRepository.count(prisma, { AND: [scope, caseSlaService.overdueWhere()] }),
      prisma.notification.count({ where: { userId: auth.user.id, readAt: null } }),
    ]);
    const n = (...s: CaseStatus[]) => s.reduce((sum, st) => sum + (byStatus[st] ?? 0), 0);
    return {
      awaitingAcceptance: n('submitted'),
      pendingAssignment: n('received', 'review'),
      inProduction: n('assigned', 'in_production', 'rework'),
      pendingQc: n('quality_control'),
      readyForDelivery: n('ready', 'out_for_delivery'),
      overdue,
      unreadNotifications: unread,
    };
  },

  async get(auth: AuthContext, idOrNumber: string): Promise<CaseDetail> {
    const row = await caseRepository.findDetail(prisma, idOrNumber, caseScope(auth));
    if (!row) throw notFound();
    return presentCase(auth, row);
  },

  async create(auth: AuthContext, body: CreateCaseBody): Promise<CaseDetail> {
    const now = Date.now();
    const isStaff = can(auth, PERMISSIONS.CASES_CREATE);
    let clinicId = body.clinicId;
    if (!isStaff) {
      // Clinic-portal users can only submit for their own clinic.
      if (!auth.user.clinicId) throw forbidden();
      clinicId = auth.user.clinicId;
    }

    const [clinic, doctor, service, patient, settings] = await Promise.all([
      clinicId ? prisma.clinic.findUnique({ where: { id: clinicId } }) : null,
      prisma.doctor.findUnique({ where: { id: body.doctorId } }),
      prisma.labService.findUnique({ where: { id: body.serviceId } }),
      body.patientId ? prisma.patient.findUnique({ where: { id: body.patientId } }) : null,
      readLabSettings(prisma),
    ]);

    const errors: FieldErrors = {};
    if (!clinic) errors.clinicId = [clinicId ? 'Select a valid clinic.' : 'Clinic is required.'];
    else if (clinic.status !== 'active') errors.clinicId = ['This clinic is inactive.'];
    if (!doctor) errors.doctorId = ['Select a valid doctor.'];
    else if (doctor.clinicId !== clinicId) errors.doctorId = ['This doctor does not belong to the selected clinic.'];
    else if (doctor.status !== 'active') errors.doctorId = ['This doctor is inactive.'];
    if (!service?.active) errors.serviceId = ['Select an active service.'];
    const teeth = [...new Set(body.teeth)].sort((a, b) => a - b);
    if (service?.unitMode === 'tooth' && teeth.length === 0) errors.teeth = ['Select at least one tooth on the chart.'];
    if (service?.unitMode === 'denture' && !body.dentureType) errors.dentureType = ['Select the denture type.'];
    if (!body.patientId && !body.newPatient) errors.patientId = ['Select a patient or enter a new patient name.'];
    if (body.patientId && (!patient || (!isStaff && patient.clinicId !== clinicId))) errors.patientId = ['Select a valid patient.'];
    const dueOverride = isStaff && body.dueAt ? new Date(body.dueAt) : null;
    if (dueOverride && dueOverride.getTime() <= now) errors.dueAt = ['The due date must be in the future.'];
    const newCode = body.newPatient?.code?.trim();
    if (!patient && newCode && (await prisma.patient.findUnique({ where: { code: newCode } }))) errors['newPatient.code'] = ['This patient reference is already in use.'];
    throwIfErrors(errors);

    const unitService = { unitMode: service!.unitMode, unitPrice: service!.unitPrice.toNumber() };
    const price = priceCase(unitService, teeth, body.dentureType, body.priority === 'urgent', settings.emergencyFeePerUnit);
    const receiveNow = isStaff && body.receiveNow;
    const sla = slaConfigOf(settings);
    const at = new Date(now);

    const id = await prisma.$transaction(async (tx) => {
      let patientId = patient?.id;
      if (!patientId && body.newPatient) {
        const code = newCode || patientCode(await nextSequence(tx, SEQUENCES.patient));
        const created = await tx.patient.create({ data: { code, name: body.newPatient.name, phone: body.newPatient.phone, clinicId } });
        patientId = created.id;
      }
      const number = caseNumber(labYear(now), await nextSequence(tx, SEQUENCES.case));
      const status: CaseStatus = receiveNow ? 'received' : 'submitted';
      const c = await tx.dentalCase.create({
        data: {
          caseNumber: number,
          patientId: patientId!,
          doctorId: doctor!.id,
          clinicId: doctor!.clinicId,
          serviceId: service!.id,
          caseType: service!.caseType,
          restorationType: service!.name,
          material: body.material || service!.defaultMaterial,
          shade: body.shade,
          teeth: service!.unitMode === 'tooth' ? teeth : [],
          dentureType: service!.unitMode === 'denture' ? body.dentureType : null,
          units: price.units,
          unitPrice: price.unitPrice,
          emergencyFee: price.emergencyFee,
          total: price.total,
          priority: body.priority,
          status,
          instructions: body.instructions,
          submittedAt: receiveNow ? null : at,
          // The 48-hour clock starts on the server, now.
          receivedAt: receiveNow ? at : null,
          dueAt: receiveNow ? caseSlaService.dueAt(at, sla, dueOverride) : null,
          createdById: auth.user.id,
        },
      });
      await recordHistory(tx, auth, c.id, null, status, receiveNow ? 'Registered at reception — 48-hour clock started' : 'Submitted through the clinic portal');
      if (receiveNow) await issueInvoice(tx, c, settings, now);
      await logActivity(tx, auth.user, { action: 'case.create', description: `Created case ${c.caseNumber}`, subjectType: 'case', subjectId: c.id, subjectLabel: c.caseNumber });
      if (receiveNow) {
        await notifyUsers(tx, await recipients(tx, RECIPIENTS.assigners), notify.caseReceived(c), { caseId: c.id, exceptUserId: auth.user.id });
      } else {
        await notifyUsers(tx, await recipients(tx, RECIPIENTS.intakeAndAdmin), notify.caseSubmitted(c, clinic!.name), { caseId: c.id, exceptUserId: auth.user.id });
      }
      return c.id;
    });
    return presentCase(auth, await caseRepository.findDetailById(prisma, id));
  },

  async update(auth: AuthContext, idOrNumber: string, body: UpdateCasePayload): Promise<CaseDetail> {
    const now = Date.now();
    const existing = await caseRepository.findDetail(prisma, idOrNumber, caseScope(auth));
    if (!existing) throw notFound();
    if (!STATUS_META[existing.status].open) throw unprocessable('Closed cases cannot be edited.');
    const unitMode = existing.service.unitMode;
    if (body.teeth && unitMode === 'tooth' && body.teeth.length === 0) throw validation({ teeth: ['Select at least one tooth.'] });

    const teeth = body.teeth && unitMode === 'tooth' ? [...new Set(body.teeth)].sort((a, b) => a - b) : existing.teeth;
    const dentureType = body.dentureType !== undefined && unitMode === 'denture' ? body.dentureType : existing.dentureType;
    const priority = body.priority ?? existing.priority;
    const settings = await readLabSettings(prisma);
    // Existing cases keep the unit price they were accepted at; only quantities and the emergency fee change.
    const price = priceCase({ unitMode, unitPrice: existing.unitPrice.toNumber() }, teeth, dentureType, priority === 'urgent', settings.emergencyFeePerUnit);
    if (existing.invoice && price.total < invoiceFigures(existing.invoice, now).paid) throw unprocessable('The new total would be lower than what has already been paid.');

    await prisma.$transaction(async (tx) => {
      await tx.dentalCase.update({
        where: { id: existing.id },
        data: {
          material: body.material ?? existing.material,
          shade: body.shade ?? existing.shade,
          instructions: body.instructions ?? existing.instructions,
          teeth,
          dentureType,
          priority,
          units: price.units,
          emergencyFee: price.emergencyFee,
          total: price.total,
        },
      });
      if (existing.invoice) {
        await tx.invoice.update({ where: { id: existing.invoice.id }, data: { subtotal: price.subtotal, emergencyFee: price.emergencyFee, total: price.total } });
      }
      await logActivity(tx, auth.user, { action: 'case.update', description: `Edited case ${existing.caseNumber}`, subjectType: 'case', subjectId: existing.id, subjectLabel: existing.caseNumber });
    });
    return presentCase(auth, await caseRepository.findDetailById(prisma, existing.id));
  },

  async remove(auth: AuthContext, idOrNumber: string) {
    const existing = await caseRepository.findDetail(prisma, idOrNumber, caseScope(auth));
    if (!existing) throw notFound();
    if (existing.invoice && (await prisma.payment.count({ where: { invoiceId: existing.invoice.id } }))) {
      throw unprocessable('This case has recorded payments and cannot be deleted. Cancel it instead.');
    }
    await prisma.$transaction(async (tx) => {
      // History, notes, files, QC, deliveries, invoice and notifications cascade with the case.
      await tx.dentalCase.delete({ where: { id: existing.id } });
      await logActivity(tx, auth.user, { action: 'case.delete', description: `Deleted case ${existing.caseNumber}`, subjectType: 'case', subjectLabel: existing.caseNumber });
    });
    // Files go only after the rows are committed away, so a failed delete never leaves rows pointing at missing files.
    await Promise.all(existing.attachments.map((a) => removeObject(a.storageKey))).catch((err: unknown) =>
      logger.warn({ err, caseId: existing.id }, 'could not remove some case files'),
    );
  },

  async addNote(auth: AuthContext, idOrNumber: string, text: string): Promise<CaseDetail> {
    const existing = await prisma.dentalCase.findFirst({ where: { AND: [caseScope(auth), { OR: [{ id: idOrNumber }, { caseNumber: idOrNumber }] }] }, select: { id: true, technicianId: true, clinicId: true } });
    if (!existing) throw notFound();
    assertCanViewCase(auth, existing);
    await prisma.caseNote.create({ data: { caseId: existing.id, text, authorId: auth.user.id } });
    await prisma.dentalCase.update({ where: { id: existing.id }, data: { updatedAt: new Date() } });
    return presentCase(auth, await caseRepository.findDetailById(prisma, existing.id));
  },
};
