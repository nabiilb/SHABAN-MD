/** Read models and side effects shared by the mock handlers (what services/resources do in a backend). */
import type {
  ActivityLogEntry,
  AppNotification,
  CaseDetail,
  CaseListItem,
  Invoice,
  LabCase,
  NotificationType,
  RoleKey,
} from '@/types/models';
import type { RelationStats } from '@/types/api';
import { invoiceTotals } from '@/lib/billing';
import { PERMISSIONS, hasPermission } from '@/lib/permissions';
import { getSlaInfo, HOUR_MS } from '@/lib/sla';
import { DONE_STATUSES, IN_LAB_STATUSES, STATUS_META, canViewCase } from '@/lib/workflow';
import { ApiError } from '@/services/api/errors';
import { actorOf } from './auth-context';
import { nextId, type MockDatabase, type MockUser } from './db';
import type { AuthedContext } from './router';

export function slaConfig(db: MockDatabase) {
  const s = db.settings;
  return { slaHours: s.slaHours, atRiskHours: s.atRiskHours, criticalHours: s.criticalHours };
}

export function invoiceView(db: MockDatabase, inv: MockDatabase['invoices'][number], now: number): Invoice {
  const t = invoiceTotals(inv.total, db.payments.filter((p) => p.invoiceId === inv.id), inv.dueDate, now);
  return { ...inv, ...t };
}

export function paymentStatusOf(db: MockDatabase, c: LabCase, now: number) {
  const inv = c.invoiceId ? db.invoices.find((i) => i.id === c.invoiceId) : undefined;
  return inv ? invoiceView(db, inv, now).status : 'unpaid';
}

export function toListItem(db: MockDatabase, c: LabCase, now: number): CaseListItem {
  const patient = db.patients.find((p) => p.id === c.patientId);
  const doctor = db.doctors.find((d) => d.id === c.doctorId);
  const clinic = db.clinics.find((k) => k.id === c.clinicId);
  const tech = c.technicianId ? db.technicians.find((t) => t.id === c.technicianId) : null;
  return {
    ...c,
    paymentStatus: paymentStatusOf(db, c, now),
    patient: { id: c.patientId, name: patient?.name ?? 'Unknown patient', code: patient?.code ?? '' },
    doctor: { id: c.doctorId, name: doctor?.name ?? 'Unknown doctor' },
    clinic: { id: c.clinicId, name: clinic?.name ?? 'Unknown clinic' },
    technician: tech ? { id: tech.id, name: tech.name } : null,
    attachmentCount: db.attachments.filter((a) => a.caseId === c.id).length,
  };
}

export function toDetail(db: MockDatabase, c: LabCase, now: number, ctx: AuthedContext): CaseDetail {
  const item = toListItem(db, c, now);
  const canFiles = hasPermission(ctx.permissions, PERMISSIONS.FILES_VIEW);
  const canMoney = hasPermission(ctx.permissions, [PERMISSIONS.INVOICES_VIEW, PERMISSIONS.PAYMENTS_VIEW], 'any');
  const inv = c.invoiceId ? db.invoices.find((i) => i.id === c.invoiceId) : undefined;
  return {
    ...item,
    patient: db.patients.find((p) => p.id === c.patientId)!,
    doctor: db.doctors.find((d) => d.id === c.doctorId)!,
    clinic: db.clinics.find((k) => k.id === c.clinicId)!,
    technician: c.technicianId ? db.technicians.find((t) => t.id === c.technicianId) ?? null : null,
    service: db.services.find((s) => s.id === c.serviceId) ?? {
      id: c.serviceId, name: c.restorationType, caseType: c.caseType, unitMode: 'tooth', unitPrice: c.unitPrice, defaultMaterial: c.material, active: false,
    },
    history: db.history.filter((h) => h.caseId === c.id).sort((a, b) => a.createdAt.localeCompare(b.createdAt)),
    attachments: canFiles ? db.attachments.filter((a) => a.caseId === c.id) : [],
    qualityChecks: db.qualityChecks.filter((q) => q.caseId === c.id).sort((a, b) => a.checkedAt.localeCompare(b.checkedAt)),
    deliveries: db.deliveries.filter((d) => d.caseId === c.id),
    invoice: canMoney && inv ? invoiceView(db, inv, now) : null,
  };
}

/** Row-level scope: technicians see their cases, clients their clinic's cases. */
export function visibleCases(ctx: AuthedContext) {
  const actor = actorOf(ctx);
  return ctx.db.cases.filter((c) => canViewCase(actor, c));
}

export function findCaseOr404(ctx: AuthedContext, id: string) {
  const c = ctx.db.cases.find((x) => x.id === id || x.caseNumber === id);
  if (!c || !canViewCase(actorOf(ctx), c)) throw new ApiError(404, 'Case not found.');
  return c;
}

/* ----------------------------- Notifications --------------------------- */

interface NotifyInput {
  type: NotificationType;
  title: string;
  message: string;
  c?: LabCase | null;
}

export function notifyUsers(db: MockDatabase, userIds: string[], input: NotifyInput, now: number, exceptUserId?: string) {
  const unique = [...new Set(userIds)].filter((u) => u !== exceptUserId);
  unique.forEach((userId) =>
    db.notifications.unshift({
      id: nextId('ntf'),
      userId,
      type: input.type,
      title: input.title,
      message: input.message,
      caseId: input.c?.id ?? null,
      caseNumber: input.c?.caseNumber ?? null,
      createdAt: new Date(now).toISOString(),
      readAt: null,
    } satisfies AppNotification),
  );
}

export function usersWithPermission(db: MockDatabase, permission: string, roles?: RoleKey[]): string[] {
  return db.users
    .filter((u) => u.active && (!roles || roles.includes(u.role)))
    .filter((u) => db.roles.find((r) => r.key === u.role)?.permissions.includes(permission))
    .map((u) => u.id);
}

export function clinicUsers(db: MockDatabase, clinicId: string) {
  return db.users.filter((u) => u.active && u.role === 'client' && u.clinicId === clinicId).map((u) => u.id);
}

export function technicianUser(db: MockDatabase, technicianId?: string | null) {
  const tech = technicianId ? db.technicians.find((t) => t.id === technicianId) : undefined;
  const user = tech ? db.users.find((u) => u.technicianId === tech.id || u.id === tech.userId) : undefined;
  return user && user.active ? [user.id] : [];
}

/**
 * Deadline watcher. A real backend runs this on a scheduler (e.g. Laravel's
 * `schedule:run` every minute); the mock runs it on each request.
 */
export function runDeadlineScan(db: MockDatabase, now: number) {
  const cfg = slaConfig(db);
  for (const c of db.cases) {
    if (!IN_LAB_STATUSES.includes(c.status) || !c.dueAt) continue;
    const info = getSlaInfo(c, now, cfg);
    const flags = (db.deadlineFlags[c.id] ??= {});
    const lab = [...technicianUser(db, c.technicianId), ...usersWithPermission(db, PERMISSIONS.CASES_ASSIGN, ['lab_manager', 'admin'])];
    if (info.state === 'overdue' && !flags.overdue) {
      flags.overdue = true;
      flags.atRisk = true;
      notifyUsers(db, [...lab, ...usersWithPermission(db, PERMISSIONS.CASES_ACCEPT, ['reception'])], {
        type: 'case_overdue',
        title: 'Case overdue',
        message: `${c.caseNumber} has exceeded the ${cfg.slaHours}-hour deadline.`,
        c,
      }, now);
    } else if ((info.state === 'at_risk' || info.state === 'critical') && !flags.atRisk) {
      flags.atRisk = true;
      const hours = Math.max(1, Math.round((info.remainingMs ?? 0) / HOUR_MS));
      notifyUsers(db, lab, {
        type: 'deadline_approaching',
        title: 'Deadline approaching',
        message: `${c.caseNumber} has about ${hours} hour${hours === 1 ? '' : 's'} remaining.`,
        c,
      }, now);
    }
  }
}

/* ------------------------------- Activity ------------------------------ */

export function logActivity(
  db: MockDatabase,
  user: Pick<MockUser, 'id' | 'name'>,
  entry: Pick<ActivityLogEntry, 'action' | 'description' | 'subjectType'> & Partial<Pick<ActivityLogEntry, 'subjectId' | 'subjectLabel'>>,
  now: number,
) {
  db.activity.unshift({ id: nextId('act'), userId: user.id, userName: user.name, createdAt: new Date(now).toISOString(), subjectId: null, subjectLabel: null, ...entry });
  if (db.activity.length > 2000) db.activity.length = 2000;
}

/* ------------------------------ Aggregates ----------------------------- */

export function relationStats(db: MockDatabase, cases: LabCase[], now: number): RelationStats {
  const cfg = slaConfig(db);
  let outstanding = 0;
  let billed = 0;
  for (const c of cases) {
    const inv = c.invoiceId ? db.invoices.find((i) => i.id === c.invoiceId) : undefined;
    if (inv) {
      const v = invoiceView(db, inv, now);
      billed += v.total;
      outstanding += v.remaining;
    }
  }
  return {
    totalCases: cases.length,
    activeCases: cases.filter((c) => STATUS_META[c.status].open).length,
    completedCases: cases.filter((c) => DONE_STATUSES.includes(c.status)).length,
    overdueCases: cases.filter((c) => IN_LAB_STATUSES.includes(c.status) && getSlaInfo(c, now, cfg).state === 'overdue').length,
    outstanding: Math.round(outstanding * 100) / 100,
    billed: Math.round(billed * 100) / 100,
  };
}

export function recentCases(db: MockDatabase, cases: LabCase[], now: number, limit = 8) {
  return [...cases]
    .sort((a, b) => b.createdAt.localeCompare(a.createdAt))
    .slice(0, limit)
    .map((c) => toListItem(db, c, now));
}
