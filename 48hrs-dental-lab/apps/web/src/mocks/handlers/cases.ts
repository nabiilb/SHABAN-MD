import { priceCase, round2 } from '@48hrs/shared/billing';
import { categoryForExtension, extensionOf, validateFile } from '@48hrs/shared/constants';
import { PERMISSIONS, hasPermission } from '@48hrs/shared/permissions';
import { computeDueAt, getSlaInfo, HOUR_MS } from '@48hrs/shared/sla';
import { fromCaseRequest, toRequestErrors, type CaseRequestBody } from '@48hrs/shared/case-requests';
import { ALL_STATUSES, CASE_ACTIONS, IN_LAB_STATUSES, OPEN_STATUSES, STATUS_META, canPerformAction, validateActionInput, type CaseActionEndpoint } from '@48hrs/shared/workflow';
import { ApiError } from '@/services/api/errors';
import type { CaseListParams, CreateCasePayload, NavCounts, UpdateCasePayload } from '@48hrs/shared/types';
import type { AttachmentCategory, CaseAttachment, CaseStatus, Delivery, LabCase, Patient } from '@48hrs/shared/types';
import { caseNumber, patientCode } from '@48hrs/shared/case-keys';
import { notify, RECIPIENTS } from '@48hrs/shared/notifications';
import { localDay, toIso } from '@48hrs/shared/dates';
import { actorOf, authenticate, authorize } from '../auth-context';
import { nextId } from '../db';
import {
  clinicUsers,
  createInvoice,
  recipients,
  findCaseOr404,
  invoiceView,
  logActivity,
  notifyUsers,
  slaConfig,
  technicianUser,
  toDetail,
  toListItem,
  visibleCases,
} from '../domain';
import { fileStore, sampleContent } from '../file-store';
import {
  forbidden,
  includesText,
  invalidTransition,
  notFound,
  paginate,
  qBool,
  qList,
  qStr,
  requireFields,
  route,
  sortItems,
  validationError,
  type AuthedContext,
  type FieldErrors,
  type MockContext,
} from '../router';

const PRIORITY_RANK = { urgent: 0, high: 1, normal: 2 } as const;

/* --------------------------------- List -------------------------------- */

route('GET', '/cases', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.CASES_VIEW);
  const { db, query: q, now } = ctx;
  const cfg = slaConfig(db);
  const statuses = qList(q, 'status') as CaseStatus[];
  const priorities = qList(q, 'priority');
  const search = qStr(q, 'search');
  const from = qStr(q, 'from');
  const to = qStr(q, 'to');
  const sla = qStr(q, 'sla') as CaseListParams['sla'];
  const endOfToday = new Date(now);
  endOfToday.setHours(23, 59, 59, 999);

  const items = visibleCases(ctx)
    .filter((c) => !statuses.length || statuses.includes(c.status))
    .filter((c) => !priorities.length || priorities.includes(c.priority))
    .filter((c) => !qStr(q, 'technicianId') || c.technicianId === qStr(q, 'technicianId'))
    .filter((c) => !qStr(q, 'doctorId') || c.doctorId === qStr(q, 'doctorId'))
    .filter((c) => !qStr(q, 'clinicId') || c.clinicId === qStr(q, 'clinicId'))
    .filter((c) => !qStr(q, 'patientId') || c.patientId === qStr(q, 'patientId'))
    .filter((c) => !qStr(q, 'caseType') || c.caseType === qStr(q, 'caseType'))
    .filter((c) => !qBool(q, 'openOnly') || OPEN_STATUSES.includes(c.status))
    .filter((c) => {
      const dueFrom = qStr(q, 'dueFrom');
      const dueTo = qStr(q, 'dueTo');
      if (!dueFrom && !dueTo) return true;
      if (!c.dueAt) return false;
      const day = localDay(c.dueAt);
      return (!dueFrom || day >= dueFrom) && (!dueTo || day <= dueTo);
    })
    .filter((c) => {
      if (!from && !to) return true;
      const day = localDay(c.receivedAt ?? c.createdAt);
      return (!from || day >= from) && (!to || day <= to);
    })
    .filter((c) => {
      if (!sla) return true;
      if (!IN_LAB_STATUSES.includes(c.status)) return false;
      const info = getSlaInfo(c, now, cfg);
      if (sla === 'overdue') return info.state === 'overdue';
      if (sla === 'at_risk') return info.state === 'at_risk' || info.state === 'critical';
      if (sla === 'on_track') return info.state === 'on_track';
      if (sla === 'due_today') return !!c.dueAt && new Date(c.dueAt).getTime() <= endOfToday.getTime() && info.state !== 'overdue';
      return true;
    })
    .map((c) => toListItem(db, c, now))
    .filter((c) => !qStr(q, 'paymentStatus') || c.paymentStatus === qStr(q, 'paymentStatus'))
    .filter((c) => includesText([c.caseNumber, c.patient.name, c.patient.code, c.doctor.name, c.clinic.name, c.restorationType, c.technician?.name], search));

  const sorted = sortItems(
    items,
    q,
    {
      caseNumber: (c) => c.caseNumber,
      patient: (c) => c.patient.name,
      doctor: (c) => c.doctor.name,
      clinic: (c) => c.clinic.name,
      caseType: (c) => c.restorationType,
      priority: (c) => -PRIORITY_RANK[c.priority],
      receivedAt: (c) => c.receivedAt ?? c.createdAt,
      dueAt: (c) => c.dueAt ?? null,
      status: (c) => STATUS_META[c.status].stage,
      technician: (c) => c.technician?.name ?? null,
      total: (c) => c.total,
      paymentStatus: (c) => c.paymentStatus,
      createdAt: (c) => c.createdAt,
    },
    'receivedAt',
  );
  return paginate(sorted, q);
});

route('GET', '/cases/counts', (raw) => {
  const ctx = authenticate(raw);
  const { db, now } = ctx;
  const cases = hasPermission(ctx.permissions, PERMISSIONS.CASES_VIEW) ? visibleCases(ctx) : [];
  const n = (...s: CaseStatus[]) => cases.filter((c) => s.includes(c.status)).length;
  const counts: NavCounts = {
    awaitingAcceptance: n('submitted'),
    pendingAssignment: n('received', 'review'),
    inProduction: n('assigned', 'in_production', 'rework'),
    pendingQc: n('quality_control'),
    readyForDelivery: n('ready', 'out_for_delivery'),
    overdue: cases.filter((c) => IN_LAB_STATUSES.includes(c.status) && getSlaInfo(c, now, slaConfig(db)).state === 'overdue').length,
    unreadNotifications: db.notifications.filter((x) => x.userId === ctx.user.id && !x.readAt).length,
  };
  return counts;
});

route('GET', '/cases/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.CASES_VIEW);
  const c = findCaseOr404(ctx, raw.params.id);
  return toDetail(ctx.db, c, ctx.now, ctx);
});

/* -------------------------------- Create ------------------------------- */

function validateCaseInput(ctx: AuthedContext, body: Partial<CreateCasePayload>) {
  const { db } = ctx;
  requireFields(body as Record<string, unknown>, { doctorId: 'Doctor', clinicId: 'Clinic', serviceId: 'Service', shade: 'Shade', priority: 'Priority' });
  const errors: FieldErrors = {};
  const clinic = db.clinics.find((k) => k.id === body.clinicId);
  const doctor = db.doctors.find((d) => d.id === body.doctorId);
  const service = db.services.find((s) => s.id === body.serviceId);
  if (!clinic) errors.clinicId = ['Select a valid clinic.'];
  else if (clinic.status !== 'active') errors.clinicId = ['This clinic is inactive.'];
  if (!doctor) errors.doctorId = ['Select a valid doctor.'];
  else if (doctor.clinicId !== body.clinicId) errors.doctorId = ['This doctor does not belong to the selected clinic.'];
  else if (doctor.status !== 'active') errors.doctorId = ['This doctor is inactive.'];
  if (!service || !service.active) errors.serviceId = ['Select an active service.'];
  if (!['normal', 'high', 'urgent'].includes(String(body.priority))) errors.priority = ['Select a priority.'];

  const teeth = Array.isArray(body.teeth) ? [...new Set(body.teeth.map(Number))] : [];
  if (teeth.some((t) => !Number.isInteger(t) || t < 1 || t > 32)) errors.teeth = ['Teeth must use universal numbering 1–32.'];
  if (service?.unitMode === 'tooth' && teeth.length === 0) errors.teeth = ['Select at least one tooth on the chart.'];
  if (service?.unitMode === 'denture' && !body.dentureType) errors.dentureType = ['Select the denture type.'];

  if (!body.patientId && !body.newPatient?.name?.trim()) errors.patientId = ['Select a patient or enter a new patient name.'];
  if (body.patientId && !db.patients.some((p) => p.id === body.patientId)) errors.patientId = ['Select a valid patient.'];
  if (body.dueAt && new Date(body.dueAt).getTime() <= ctx.now) errors.dueAt = ['The due date must be in the future.'];
  if ((body.instructions ?? '').length > 2000) errors.instructions = ['Keep instructions under 2000 characters.'];
  if (Object.keys(errors).length) throw validationError(errors);
  return { clinic: clinic!, doctor: doctor!, service: service!, teeth: teeth.sort((a, b) => a - b) };
}

function history(ctx: AuthedContext, c: LabCase, from: CaseStatus | null, to: CaseStatus, note?: string) {
  ctx.db.history.push({
    id: nextId('hst'),
    caseId: c.id,
    fromStatus: from,
    toStatus: to,
    userId: ctx.user.id,
    userName: ctx.user.name,
    userRole: ctx.user.role,
    note: note?.trim() || undefined,
    createdAt: toIso(ctx.now),
  });
}

route('POST', '/cases', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, [PERMISSIONS.CASES_CREATE, PERMISSIONS.CASES_SUBMIT], 'any');
  const { db, now } = ctx;
  const body = { ...((raw.body ?? {}) as Partial<CreateCasePayload>) };
  const isStaff = hasPermission(ctx.permissions, PERMISSIONS.CASES_CREATE);

  // Clinic-portal users can only submit for their own clinic.
  if (!isStaff) {
    if (!ctx.user.clinicId) throw forbidden();
    body.clinicId = ctx.user.clinicId;
  }
  const { doctor, service, teeth } = validateCaseInput(ctx, body);

  let patient: Patient | undefined = body.patientId ? db.patients.find((p) => p.id === body.patientId) : undefined;
  if (!patient && body.newPatient) {
    const code = body.newPatient.code?.trim();
    if (code && db.patients.some((p) => p.code.toLowerCase() === code.toLowerCase())) {
      throw validationError({ 'newPatient.code': ['This patient reference is already in use.'] });
    }
    db.counters.patient += 1;
    patient = {
      id: nextId('pat'),
      code: code || patientCode(db.counters.patient),
      name: body.newPatient.name.trim(),
      phone: body.newPatient.phone?.trim() ?? '',
      email: '',
      gender: null,
      dateOfBirth: null,
      clinicId: body.clinicId,
      notes: '',
      createdAt: toIso(now),
    };
    db.patients.push(patient);
  }

  const urgent = body.priority === 'urgent';
  const price = priceCase(service, teeth, body.dentureType, urgent, db.settings.emergencyFeePerUnit);
  db.counters.case += 1;
  const receiveNow = isStaff && body.receiveNow !== false;
  const c: LabCase = {
    id: nextId('cas'),
    caseNumber: caseNumber(new Date(now).getFullYear(), db.counters.case),
    patientId: patient!.id,
    doctorId: doctor.id,
    clinicId: doctor.clinicId,
    serviceId: service.id,
    caseType: service.caseType,
    restorationType: service.name,
    material: body.material?.trim() || service.defaultMaterial,
    shade: String(body.shade),
    teeth: service.unitMode === 'tooth' ? teeth : [],
    dentureType: service.unitMode === 'denture' ? body.dentureType ?? null : null,
    units: price.units,
    unitPrice: price.unitPrice,
    emergencyFee: price.emergencyFee,
    total: price.total,
    priority: body.priority ?? 'normal',
    status: receiveNow ? 'received' : 'submitted',
    technicianId: null,
    instructions: body.instructions?.trim() ?? '',
    notes: [],
    reworkCount: 0,
    submittedAt: receiveNow ? null : toIso(now),
    receivedAt: receiveNow ? toIso(now) : null,
    dueAt: receiveNow ? (body.dueAt ? toIso(new Date(body.dueAt).getTime()) : computeDueAt(new Date(now), db.settings.slaHours).toISOString()) : null,
    paymentStatus: 'unpaid',
    createdById: ctx.user.id,
    createdAt: toIso(now),
    updatedAt: toIso(now),
  };
  db.cases.unshift(c);
  history(ctx, c, null, c.status, receiveNow ? 'Registered at reception — 48-hour clock started' : 'Submitted through the clinic portal');
  if (receiveNow) createInvoice(db, c, now);

  logActivity(db, ctx.user, { action: 'case.create', description: `Created case ${c.caseNumber}`, subjectType: 'case', subjectId: c.id, subjectLabel: c.caseNumber }, now);
  if (receiveNow) {
    notifyUsers(db, recipients(db, RECIPIENTS.assigners), { ...notify.caseReceived(c), c }, now, ctx.user.id);
  } else {
    notifyUsers(db, recipients(db, RECIPIENTS.intakeAndAdmin), { ...notify.caseSubmitted(c, db.clinics.find((k) => k.id === c.clinicId)?.name ?? 'the clinic'), c }, now, ctx.user.id);
  }
  return toDetail(db, c, now, ctx);
});

/* --------------------------------- Edit -------------------------------- */

route('PATCH', '/cases/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.CASES_EDIT);
  const c = findCaseOr404(ctx, raw.params.id);
  if (!STATUS_META[c.status].open) throw new ApiError(422, 'Closed cases cannot be edited.');
  const body = (raw.body ?? {}) as UpdateCasePayload;
  const service = ctx.db.services.find((s) => s.id === c.serviceId);

  const errors: FieldErrors = {};
  if (body.teeth && service?.unitMode === 'tooth' && body.teeth.length === 0) errors.teeth = ['Select at least one tooth.'];
  if (body.shade !== undefined && !body.shade.trim()) errors.shade = ['Shade is required.'];
  if (Object.keys(errors).length) throw validationError(errors);

  if (body.material !== undefined) c.material = body.material.trim();
  if (body.shade !== undefined) c.shade = body.shade.trim();
  if (body.instructions !== undefined) c.instructions = body.instructions.trim();
  if (body.dentureType !== undefined && service?.unitMode === 'denture') c.dentureType = body.dentureType;
  if (body.teeth && service?.unitMode === 'tooth') c.teeth = [...new Set(body.teeth)].sort((a, b) => a - b);
  if (body.priority) c.priority = body.priority;

  if (service) {
    const inv = c.invoiceId ? ctx.db.invoices.find((i) => i.id === c.invoiceId) : undefined;
    const paid = inv ? invoiceView(ctx.db, inv, ctx.now).paid : 0;
    const price = priceCase({ unitMode: service.unitMode, unitPrice: c.unitPrice }, c.teeth, c.dentureType, c.priority === 'urgent', ctx.db.settings.emergencyFeePerUnit);
    if (inv && price.total < paid) throw new ApiError(422, 'The new total would be lower than what has already been paid.');
    Object.assign(c, { units: price.units, emergencyFee: price.emergencyFee, total: price.total });
    if (inv) Object.assign(inv, { subtotal: price.subtotal, emergencyFee: price.emergencyFee, total: price.total });
  }
  c.updatedAt = toIso(ctx.now);
  logActivity(ctx.db, ctx.user, { action: 'case.update', description: `Edited case ${c.caseNumber}`, subjectType: 'case', subjectId: c.id, subjectLabel: c.caseNumber }, ctx.now);
  return toDetail(ctx.db, c, ctx.now, ctx);
});

route('DELETE', '/cases/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.CASES_DELETE);
  const c = findCaseOr404(ctx, raw.params.id);
  if (c.invoiceId && ctx.db.payments.some((p) => p.invoiceId === c.invoiceId)) {
    throw new ApiError(422, 'This case has recorded payments and cannot be deleted. Cancel it instead.');
  }
  const { db } = ctx;
  db.cases = db.cases.filter((x) => x.id !== c.id);
  db.history = db.history.filter((h) => h.caseId !== c.id);
  db.attachments = db.attachments.filter((a) => a.caseId !== c.id);
  db.qualityChecks = db.qualityChecks.filter((q) => q.caseId !== c.id);
  db.deliveries = db.deliveries.filter((d) => d.caseId !== c.id);
  db.invoices = db.invoices.filter((i) => i.caseId !== c.id);
  db.notifications = db.notifications.filter((n) => n.caseId !== c.id);
  logActivity(db, ctx.user, { action: 'case.delete', description: `Deleted case ${c.caseNumber}`, subjectType: 'case', subjectLabel: c.caseNumber }, ctx.now);
  return null;
});

/* ------------------------------- Workflow ------------------------------ */

/** Minimal shape checks for each workflow endpoint (the API does the same with Zod). */
function requestShapeErrors(endpoint: CaseActionEndpoint, b: Record<string, unknown>): FieldErrors {
  const e: FieldErrors = {};
  if (endpoint === 'status' && !ALL_STATUSES.includes(b.status as CaseStatus)) e.status = ['Choose a valid status.'];
  if (endpoint === 'qc' && b.result !== 'pass' && b.result !== 'fail') e.result = ['Result must be pass or fail.'];
  if (endpoint === 'qc' && !Array.isArray(b.issues)) e.issues = ['Issues must be a list.'];
  if (endpoint === 'delivery' && b.status !== 'out_for_delivery' && b.status !== 'delivered') e.status = ['Status must be out_for_delivery or delivered.'];
  return e;
}

function performWorkflow(raw: MockContext, endpoint: CaseActionEndpoint) {
  const ctx = authenticate(raw);
  const { db, now } = ctx;
  const request = (raw.body ?? {}) as Record<string, unknown>;
  const shapeErrors = requestShapeErrors(endpoint, request);
  if (Object.keys(shapeErrors).length) throw validationError(shapeErrors);
  const c = findCaseOr404(ctx, raw.params.id);
  const body = fromCaseRequest(endpoint, request as unknown as CaseRequestBody, c.status);
  if (!body) throw invalidTransition(c.status);
  const action = body.action;
  const def = CASE_ACTIONS[action];

  if (!def.from.includes(c.status)) throw invalidTransition(c.status);
  if (!canPerformAction(action, c, actorOf(ctx))) throw forbidden();
  // Validate every input before anything is written, so a 422 never leaves a half-applied transition.
  const existingInvoice = c.invoiceId ? db.invoices.find((i) => i.id === c.invoiceId) : undefined;
  const maxPayment = existingInvoice ? invoiceView(db, existingInvoice, now).remaining : c.total;
  const inputErrors = validateActionInput(body, { maxPayment });
  if (Object.keys(inputErrors).length) throw validationError(toRequestErrors(endpoint, Object.fromEntries(Object.entries(inputErrors).map(([k, m]) => [k, [m]]))));
  if (action === 'accept' && body.payment && !hasPermission(ctx.permissions, PERMISSIONS.PAYMENTS_RECORD)) throw forbidden();
  const assignee = action === 'assign' ? db.technicians.find((t) => t.id === body.technicianId) : undefined;
  if (action === 'assign' && !assignee) throw validationError({ technicianId: ['Choose a technician.'] });
  if (assignee && !assignee.active) throw validationError({ technicianId: ['This technician is inactive.'] });
  const note = body.note?.trim() ?? '';

  const from = c.status;
  const nowIso = toIso(now);
  const clinicName = db.clinics.find((k) => k.id === c.clinicId)?.name ?? 'the clinic';
  let historyNote = note;

  switch (action) {
    case 'accept': {
      c.receivedAt = nowIso;
      c.dueAt = computeDueAt(new Date(now), db.settings.slaHours).toISOString();
      const inv = c.invoiceId ? db.invoices.find((i) => i.id === c.invoiceId) : createInvoice(db, c, now);
      if (body.payment && inv) {
        db.payments.push({ id: nextId('pay'), invoiceId: inv.id, amount: round2(body.payment.amount), method: body.payment.method, reference: body.payment.reference ?? '', notes: 'Taken at acceptance', receivedById: ctx.user.id, receivedByName: ctx.user.name, paidAt: nowIso });
      }
      historyNote = note || `Accepted by ${ctx.user.name} — 48-hour clock started`;
      notifyUsers(db, recipients(db, RECIPIENTS.assigners), { ...notify.caseAccepted(c), c }, now, ctx.user.id);
      break;
    }
    case 'request_correction':
      notifyUsers(db, clinicUsers(db, c.clinicId), { ...notify.correctionRequested(c, note), c }, now);
      break;
    case 'resubmit':
      c.submittedAt = nowIso;
      notifyUsers(db, recipients(db, RECIPIENTS.intake), { ...notify.caseResubmitted(c, clinicName), c }, now);
      break;
    case 'reject':
      notifyUsers(db, clinicUsers(db, c.clinicId), { ...notify.caseRejected(c, note), c }, now);
      break;
    case 'assign': {
      const tech = assignee!;
      const reassigned = !!c.technicianId && c.technicianId !== tech.id;
      c.technicianId = tech.id;
      c.assignedAt = nowIso;
      historyNote = [`${reassigned ? 'Reassigned' : 'Assigned'} to ${tech.name}`, note].filter(Boolean).join(' — ');
      const hours = c.dueAt ? Math.max(0, Math.round((new Date(c.dueAt).getTime() - now) / HOUR_MS)) : null;
      notifyUsers(db, technicianUser(db, tech.id), { ...notify.caseAssigned(c, hours), c }, now);
      break;
    }
    case 'start_production':
      c.productionStartedAt ??= nowIso;
      break;
    case 'start_rework':
      historyNote = note || 'Rework started';
      break;
    case 'submit_qc':
      c.productionCompletedAt = nowIso;
      historyNote = note || 'Production completed — submitted for quality control';
      notifyUsers(db, recipients(db, RECIPIENTS.inspectors), { ...notify.qcRequired(c), c }, now, ctx.user.id);
      break;
    case 'qc_pass': {
      db.qualityChecks.push({ id: nextId('qc'), caseId: c.id, result: 'passed', reworkRequired: false, issues: body.qc?.issues ?? [], notes: body.qc?.notes?.trim() || note, checkedById: ctx.user.id, checkedByName: ctx.user.name, checkedAt: nowIso });
      c.qcCompletedAt = nowIso;
      c.readyAt = nowIso;
      const existing = db.deliveries.find((d) => d.caseId === c.id && d.status !== 'delivered');
      if (!existing) {
        db.deliveries.push({ id: nextId('dlv'), caseId: c.id, status: 'ready', method: 'clinic_pickup', recordedById: ctx.user.id, recordedByName: ctx.user.name, createdAt: nowIso, dispatchedAt: null, deliveredAt: null });
      }
      historyNote = note || body.qc?.notes?.trim() || 'QC passed';
      notifyUsers(db, recipients(db, RECIPIENTS.dispatchers), { ...notify.caseReady(c), c }, now, ctx.user.id);
      break;
    }
    case 'qc_fail': {
      const issues = body.qc?.issues ?? [];
      const qcNotes = body.qc?.notes?.trim() || note;
      db.qualityChecks.push({ id: nextId('qc'), caseId: c.id, result: 'failed', reworkRequired: true, issues, notes: qcNotes, checkedById: ctx.user.id, checkedByName: ctx.user.name, checkedAt: nowIso });
      c.reworkCount += 1;
      historyNote = `QC failed — ${qcNotes}`;
      notifyUsers(db, technicianUser(db, c.technicianId), { ...notify.qcFailed(c, qcNotes), c }, now);
      break;
    }
    case 'dispatch':
    case 'deliver': {
      const d = body.delivery;
      if (!d) break; // validated above
      let rec = db.deliveries.find((x) => x.caseId === c.id && x.status !== 'delivered');
      if (!rec) {
        rec = { id: nextId('dlv'), caseId: c.id, status: 'ready', method: d.method, recordedById: ctx.user.id, recordedByName: ctx.user.name, createdAt: nowIso, dispatchedAt: null, deliveredAt: null } satisfies Delivery;
        db.deliveries.push(rec);
      }
      rec.method = d.method;
      rec.courierName = d.courierName?.trim() || rec.courierName;
      rec.notes = d.notes?.trim() || rec.notes;
      rec.recordedById = ctx.user.id;
      rec.recordedByName = ctx.user.name;
      if (action === 'dispatch') {
        rec.status = 'out_for_delivery';
        rec.dispatchedAt = nowIso;
        historyNote = note || `Dispatched${rec.courierName ? ` with ${rec.courierName}` : ''}`;
        notifyUsers(db, clinicUsers(db, c.clinicId), { ...notify.caseDispatched(c), c }, now);
      } else {
        rec.status = 'delivered';
        rec.deliveredAt = nowIso;
        rec.deliveredTo = d.deliveredTo?.trim() || clinicName;
        rec.receivedBy = d.receivedBy!.trim();
        c.deliveredAt = nowIso;
        const sla = getSlaInfo(c, now, slaConfig(db));
        historyNote = note || `Delivered to ${rec.deliveredTo}, received by ${rec.receivedBy} — ${sla.state === 'met' ? 'within' : 'outside'} the 48-hour window`;
        notifyUsers(db, [...clinicUsers(db, c.clinicId), ...recipients(db, RECIPIENTS.assigners)], { ...notify.caseDelivered(c, rec.deliveredTo), c }, now, ctx.user.id);
      }
      break;
    }
    case 'confirm_receipt':
      c.completedAt = nowIso;
      historyNote = note || 'Receipt confirmed by the clinic';
      break;
    case 'cancel':
      c.cancelledAt = nowIso;
      break;
    case 'start_review':
      break;
  }

  c.status = def.to;
  c.updatedAt = nowIso;
  history(ctx, c, from, c.status, historyNote);
  logActivity(db, ctx.user, { action: `case.${action}`, description: `${def.label}: ${c.caseNumber}`, subjectType: 'case', subjectId: c.id, subjectLabel: c.caseNumber }, now);
  return toDetail(db, c, now, ctx);
}

(['status', 'assign', 'qc', 'rework', 'delivery'] as const).forEach((endpoint) => route('POST', `/cases/:id/${endpoint}`, (raw) => performWorkflow(raw, endpoint)));

route('POST', '/cases/:id/notes', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, [PERMISSIONS.CASES_EDIT, PERMISSIONS.CASES_UPDATE_STATUS, PERMISSIONS.QC_PERFORM, PERMISSIONS.CASES_ASSIGN, PERMISSIONS.DELIVERY_MANAGE], 'any');
  const c = findCaseOr404(ctx, raw.params.id);
  const text = String((raw.body as { text?: string })?.text ?? '').trim();
  if (!text) throw validationError({ text: ['Write a note first.'] });
  if (text.length > 1000) throw validationError({ text: ['Keep notes under 1000 characters.'] });
  c.notes.push({ id: nextId('note'), text, authorId: ctx.user.id, authorName: ctx.user.name, createdAt: toIso(ctx.now) });
  c.updatedAt = toIso(ctx.now);
  return toDetail(ctx.db, c, ctx.now, ctx);
});

/* ------------------------------ Attachments ---------------------------- */

route('POST', '/cases/:id/attachments', async (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, [PERMISSIONS.FILES_VIEW, PERMISSIONS.FILES_UPLOAD]);
  const c = findCaseOr404(ctx, raw.params.id);
  const file = raw.formData?.get('file');
  if (!(file instanceof Blob)) throw validationError({ file: ['Choose a file to upload.'] });
  const name = (file as File).name || 'upload';
  const error = validateFile({ name, size: file.size });
  if (error) throw validationError({ file: [error] });
  const ext = extensionOf(name);
  const category = (String(raw.formData?.get('category') ?? '') || categoryForExtension(ext)) as AttachmentCategory;

  // Simulated transfer so the progress bar reflects file size, like a real upload.
  const duration = Math.min(2500, 300 + (file.size / 1_048_576) * 90);
  const steps = 10;
  for (let i = 1; i <= steps; i++) {
    await new Promise((r) => setTimeout(r, duration / steps));
    raw.onUploadProgress?.({ loaded: Math.round((file.size * i) / steps), total: file.size, percent: Math.round((i / steps) * 100) });
  }

  const att: CaseAttachment = {
    id: nextId('att'),
    caseId: c.id,
    name,
    mimeType: file.type || 'application/octet-stream',
    extension: ext,
    size: file.size,
    category,
    uploadedById: ctx.user.id,
    uploadedByName: ctx.user.name,
    createdAt: toIso(ctx.now),
    url: null,
  };
  await fileStore.put(att.id, file);
  ctx.db.attachments.push(att);
  logActivity(ctx.db, ctx.user, { action: 'case.file_upload', description: `Uploaded ${name} to ${c.caseNumber}`, subjectType: 'case', subjectId: c.id, subjectLabel: c.caseNumber }, ctx.now);
  return att;
});

route('DELETE', '/cases/:id/attachments/:attachmentId', async (raw) => {
  const ctx = authenticate(raw);
  const c = findCaseOr404(ctx, raw.params.id);
  const att = ctx.db.attachments.find((a) => a.id === raw.params.attachmentId && a.caseId === c.id);
  if (!att) throw notFound();
  const own = att.uploadedById === ctx.user.id && hasPermission(ctx.permissions, PERMISSIONS.FILES_UPLOAD);
  if (!own) authorize(ctx, PERMISSIONS.FILES_DELETE);
  ctx.db.attachments = ctx.db.attachments.filter((a) => a !== att);
  await fileStore.remove(att.id);
  logActivity(ctx.db, ctx.user, { action: 'case.file_delete', description: `Removed ${att.name} from ${c.caseNumber}`, subjectType: 'case', subjectId: c.id, subjectLabel: c.caseNumber }, ctx.now);
  return null;
});

route('GET', '/cases/:id/attachments/:attachmentId/download', async (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.FILES_VIEW);
  const c = findCaseOr404(ctx, raw.params.id);
  const att = ctx.db.attachments.find((a) => a.id === raw.params.attachmentId && a.caseId === c.id);
  if (!att) throw notFound();
  const blob = (await fileStore.get(att.id)) ?? (await sampleContent(att.name, att.extension, c.caseNumber));
  return blob;
});

