/**
 * Every case status change. The request is translated to a workflow action
 * (packages/shared/src/case-requests.ts) and checked, in this order:
 *   404 — the case is outside the caller's scope
 *   409 — the step is not a valid transition from the current status
 *   403 — the caller may not perform it (permission, assigned technician, own clinic)
 *   422 — the step's input is incomplete or invalid (shared validateActionInput)
 * Then everything is written in one transaction. The status update is
 * conditional on the status we validated against, so two people acting on the
 * same case at once cannot both succeed: the second gets a 409.
 */
import { fromCaseRequest, toRequestErrors } from '@48hrs/shared/case-requests';
import { API_ERRORS } from '@48hrs/shared/errors';
import { notify, RECIPIENTS } from '@48hrs/shared/notifications';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import {
  caseAssignSchema,
  caseDeliverySchema,
  caseQcSchema,
  caseReworkSchema,
  caseStatusSchema,
} from '@48hrs/shared/schemas';
import { HOUR_MS } from '@48hrs/shared/sla';
import type { CaseActionPayload, CaseDetail, CaseStatus } from '@48hrs/shared/types';
import { CASE_ACTIONS, STATUS_META, canPerformAction, validateActionInput, type CaseActionEndpoint } from '@48hrs/shared/workflow';
import type { Prisma } from '../generated/prisma/client.ts';
import { conflict, forbidden, notFound, validation } from '../lib/errors.ts';
import { prisma, type Tx } from '../lib/prisma.ts';
import { parseBody } from '../middleware/validate.ts';
import { clinicRecipients, notifyUsers, recipients, technicianRecipients } from '../notifications/dispatcher.ts';
import { can, caseScope } from '../policies/case-policy.ts';
import { logActivity } from '../repositories/activity-repository.ts';
import { caseRepository } from '../repositories/case-repository.ts';
import { invoiceFigures } from '../repositories/mappers.ts';
import { readLabSettings, slaConfigOf } from '../repositories/settings-repository.ts';
import { actorOf, type AuthContext } from '../types/auth.ts';
import { iso } from '../utils/dates.ts';
import { caseSlaService } from './case-sla-service.ts';
import { presentCase, recordHistory } from './case-service.ts';
import { issueInvoice } from './invoice-issuer.ts';
import { recordPaymentTx } from './payment-ledger.ts';

const SCHEMAS = {
  status: caseStatusSchema,
  assign: caseAssignSchema,
  qc: caseQcSchema,
  rework: caseReworkSchema,
  delivery: caseDeliverySchema,
} as const;

const workflowInclude = {
  invoice: true,
  clinic: { select: { name: true } },
  technician: { select: { id: true, name: true } },
  deliveries: { where: { status: { not: 'delivered' } }, orderBy: { createdAt: 'desc' }, take: 1 },
} satisfies Prisma.DentalCaseInclude;

function invalidTransition(current: CaseStatus) {
  return conflict(API_ERRORS.invalidTransition, { currentStatus: current }, { status: [`The case is currently "${STATUS_META[current].label}".`] });
}

export const caseWorkflowService = {
  async perform(auth: AuthContext, idOrNumber: string, endpoint: CaseActionEndpoint, rawBody: unknown): Promise<CaseDetail> {
    const request = parseBody(SCHEMAS[endpoint], rawBody);
    const now = Date.now();
    const c = await prisma.dentalCase.findFirst({
      where: { AND: [caseScope(auth), { OR: [{ id: idOrNumber }, { caseNumber: idOrNumber }] }] },
      include: workflowInclude,
    });
    if (!c) throw notFound();

    const payload = fromCaseRequest(endpoint, request, c.status);
    if (!payload) throw invalidTransition(c.status);
    const action = payload.action;
    const def = CASE_ACTIONS[action];
    if (!def.from.includes(c.status)) throw invalidTransition(c.status);
    if (!canPerformAction(action, c, actorOf(auth))) throw forbidden();

    const maxPayment = c.invoice ? invoiceFigures(c.invoice, now).remaining : c.total.toNumber();
    const inputErrors = validateActionInput(payload, { maxPayment });
    if (Object.keys(inputErrors).length) {
      throw validation(toRequestErrors(endpoint, Object.fromEntries(Object.entries(inputErrors).map(([k, m]) => [k, [m]]))));
    }
    if (action === 'accept' && payload.payment && !can(auth, PERMISSIONS.PAYMENTS_RECORD)) throw forbidden();
    const assignee = action === 'assign' ? await prisma.technician.findUnique({ where: { id: payload.technicianId! } }) : null;
    if (action === 'assign' && !assignee) throw validation({ technicianId: ['Choose a technician.'] });
    if (assignee && !assignee.active) throw validation({ technicianId: ['This technician is inactive.'] });

    const settings = await readLabSettings(prisma);
    await prisma.$transaction(async (tx) => {
      const at = new Date(now);
      const data: Prisma.DentalCaseUncheckedUpdateManyInput = { status: def.to };
      switch (action) {
        case 'accept':
          data.receivedAt = at;
          data.dueAt = caseSlaService.dueAt(at, slaConfigOf(settings));
          break;
        case 'resubmit':
          data.submittedAt = at;
          break;
        case 'assign':
          data.technicianId = assignee!.id;
          data.assignedAt = at;
          break;
        case 'start_production':
          if (!c.productionStartedAt) data.productionStartedAt = at;
          break;
        case 'submit_qc':
          data.productionCompletedAt = at;
          break;
        case 'qc_pass':
          data.qcCompletedAt = at;
          data.readyAt = at;
          break;
        case 'qc_fail':
          data.reworkCount = { increment: 1 };
          break;
        case 'deliver':
          data.deliveredAt = at;
          break;
        case 'confirm_receipt':
          data.completedAt = at;
          break;
        case 'cancel':
          data.cancelledAt = at;
          break;
      }
      // Conditional on the status we validated: a concurrent change makes this a 409, not a double transition.
      const { count } = await tx.dentalCase.updateMany({ where: { id: c.id, status: c.status }, data });
      if (!count) throw invalidTransition((await tx.dentalCase.findUniqueOrThrow({ where: { id: c.id }, select: { status: true } })).status);

      const historyNote = await applySideEffects(tx, auth, c, payload, { now, settings, assignee });
      await recordHistory(tx, auth, c.id, c.status, def.to, historyNote);
      await logActivity(tx, auth.user, { action: `case.${action}`, description: `${def.label}: ${c.caseNumber}`, subjectType: 'case', subjectId: c.id, subjectLabel: c.caseNumber });
    });

    return presentCase(auth, await caseRepository.findDetailById(prisma, c.id));
  },
};

type WorkflowCase = Prisma.DentalCaseGetPayload<{ include: typeof workflowInclude }>;

/** Records, payments and notifications that accompany each step; returns the history note. */
async function applySideEffects(
  tx: Tx,
  auth: AuthContext,
  c: WorkflowCase,
  p: CaseActionPayload,
  ctx: { now: number; settings: Awaited<ReturnType<typeof readLabSettings>>; assignee: { id: string; name: string } | null },
): Promise<string> {
  const { now, settings } = ctx;
  const at = new Date(now);
  const note = p.note?.trim() ?? '';
  const except = { caseId: c.id, exceptUserId: auth.user.id };
  const clinicName = c.clinic.name;

  switch (p.action) {
    case 'accept': {
      const invoice = c.invoice ?? (await issueInvoice(tx, c, settings, now));
      if (p.payment) {
        await recordPaymentTx(tx, invoice.id, { amount: p.payment.amount, method: p.payment.method, reference: p.payment.reference, notes: 'Taken at acceptance' }, auth.user.id, { now, fieldPrefix: 'payment.' });
      }
      await notifyUsers(tx, await recipients(tx, RECIPIENTS.assigners), notify.caseAccepted(c), except);
      return note || `Accepted by ${auth.user.name} — 48-hour clock started`;
    }
    case 'request_correction':
      await notifyUsers(tx, await clinicRecipients(tx, c.clinicId), notify.correctionRequested(c, note), { caseId: c.id });
      return note;
    case 'resubmit':
      await notifyUsers(tx, await recipients(tx, RECIPIENTS.intake), notify.caseResubmitted(c, clinicName), { caseId: c.id });
      return note;
    case 'reject':
      await notifyUsers(tx, await clinicRecipients(tx, c.clinicId), notify.caseRejected(c, note), { caseId: c.id });
      return note;
    case 'assign': {
      const tech = ctx.assignee!;
      const reassigned = !!c.technicianId && c.technicianId !== tech.id;
      await tx.caseAssignment.updateMany({ where: { caseId: c.id, unassignedAt: null }, data: { unassignedAt: at } });
      await tx.caseAssignment.create({ data: { caseId: c.id, technicianId: tech.id, assignedById: auth.user.id, note: note || null, assignedAt: at } });
      const hours = c.dueAt ? Math.max(0, Math.round((c.dueAt.getTime() - now) / HOUR_MS)) : null;
      await notifyUsers(tx, await technicianRecipients(tx, tech.id), notify.caseAssigned(c, hours), { caseId: c.id });
      return [`${reassigned ? 'Reassigned' : 'Assigned'} to ${tech.name}`, note].filter(Boolean).join(' — ');
    }
    case 'start_production':
    case 'start_review':
    case 'cancel':
      return note;
    case 'start_rework':
      return note || 'Rework started';
    case 'submit_qc':
      await notifyUsers(tx, await recipients(tx, RECIPIENTS.inspectors), notify.qcRequired(c), except);
      return note || 'Production completed — submitted for quality control';
    case 'qc_pass': {
      const notes = p.qc?.notes?.trim() || note;
      await tx.qualityCheck.create({
        data: { caseId: c.id, result: 'passed', reworkRequired: false, notes, checkedById: auth.user.id, checkedAt: at, issues: { create: (p.qc?.issues ?? []).map((issue) => ({ issue })) } },
      });
      if (!c.deliveries.length) {
        await tx.delivery.create({ data: { caseId: c.id, status: 'ready', method: 'clinic_pickup', recordedById: auth.user.id, createdAt: at } });
      }
      await notifyUsers(tx, await recipients(tx, RECIPIENTS.dispatchers), notify.caseReady(c), except);
      return notes || 'QC passed';
    }
    case 'qc_fail': {
      const notes = p.qc?.notes?.trim() || note;
      await tx.qualityCheck.create({
        data: { caseId: c.id, result: 'failed', reworkRequired: true, notes, checkedById: auth.user.id, checkedAt: at, issues: { create: (p.qc?.issues ?? []).map((issue) => ({ issue })) } },
      });
      await notifyUsers(tx, await technicianRecipients(tx, c.technicianId), notify.qcFailed(c, notes), { caseId: c.id });
      return `QC failed — ${notes}`;
    }
    case 'dispatch':
    case 'deliver': {
      const d = p.delivery!;
      const open = c.deliveries[0];
      const common = {
        method: d.method,
        courierName: d.courierName?.trim() || open?.courierName || null,
        notes: d.notes?.trim() || open?.notes || null,
        recordedById: auth.user.id,
      };
      if (p.action === 'dispatch') {
        const fields = { ...common, status: 'out_for_delivery' as const, dispatchedAt: at };
        if (open) await tx.delivery.update({ where: { id: open.id }, data: fields });
        else await tx.delivery.create({ data: { caseId: c.id, ...fields, createdAt: at } });
        await notifyUsers(tx, await clinicRecipients(tx, c.clinicId), notify.caseDispatched(c), { caseId: c.id });
        return note || `Dispatched${common.courierName ? ` with ${common.courierName}` : ''}`;
      }
      const deliveredTo = d.deliveredTo?.trim() || clinicName;
      const receivedBy = d.receivedBy!.trim();
      const fields = { ...common, status: 'delivered' as const, deliveredAt: at, deliveredTo, receivedBy };
      if (open) await tx.delivery.update({ where: { id: open.id }, data: fields });
      else await tx.delivery.create({ data: { caseId: c.id, ...fields, createdAt: at } });
      const outcome = caseSlaService.outcome({ status: 'delivered', receivedAt: iso(c.receivedAt), dueAt: iso(c.dueAt), deliveredAt: at.toISOString() }, slaConfigOf(settings), now);
      await notifyUsers(tx, [...(await clinicRecipients(tx, c.clinicId)), ...(await recipients(tx, RECIPIENTS.assigners))], notify.caseDelivered(c, deliveredTo), except);
      return note || `Delivered to ${deliveredTo}, received by ${receivedBy} — ${outcome === 'completed_on_time' ? 'within' : 'outside'} the ${settings.slaHours}-hour window`;
    }
    case 'confirm_receipt':
      return note || 'Receipt confirmed by the clinic';
  }
}
