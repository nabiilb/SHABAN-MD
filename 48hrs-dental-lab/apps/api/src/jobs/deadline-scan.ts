/**
 * Finds cases whose deadline is near or past and raises each alert once.
 *
 * A case is "claimed" for an alert with a conditional UPDATE (… WHERE
 * at_risk_notified_at IS NULL), so however many workers run at the same time
 * — or however often — every alert is sent exactly once per case.
 */
import { deadlineAlert, RECIPIENTS } from '@48hrs/shared/notifications';
import { HOUR_MS } from '@48hrs/shared/sla';
import { IN_LAB_STATUSES } from '@48hrs/shared/workflow';
import { prisma } from '../lib/prisma.ts';
import { notifyUsers, recipients, technicianRecipients } from '../notifications/dispatcher.ts';
import { caseSlaService } from '../services/case-sla-service.ts';
import { iso } from '../utils/dates.ts';

export interface ScanResult {
  checked: number;
  atRisk: number;
  overdue: number;
  notifications: number;
}

export async function runDeadlineScan(now = Date.now()): Promise<ScanResult> {
  const sla = await caseSlaService.config(prisma);
  const at = new Date(now);
  // Only cases inside the at-risk window that still owe an alert.
  const candidates = await prisma.dentalCase.findMany({
    where: {
      status: { in: IN_LAB_STATUSES },
      dueAt: { not: null, lte: new Date(now + sla.atRiskHours * HOUR_MS) },
      OR: [{ atRiskNotifiedAt: null }, { overdueNotifiedAt: null, dueAt: { lte: at } }],
    },
    select: { id: true, caseNumber: true, status: true, technicianId: true, receivedAt: true, dueAt: true, deliveredAt: true, atRiskNotifiedAt: true, overdueNotifiedAt: true },
  });

  const result: ScanResult = { checked: candidates.length, atRisk: 0, overdue: 0, notifications: 0 };
  for (const c of candidates) {
    const alert = deadlineAlert(
      { caseNumber: c.caseNumber, status: c.status, receivedAt: iso(c.receivedAt), dueAt: iso(c.dueAt), deliveredAt: iso(c.deliveredAt) },
      { atRisk: !!c.atRiskNotifiedAt, overdue: !!c.overdueNotifiedAt },
      now,
      sla,
    );
    if (!alert) continue;

    await prisma.$transaction(async (tx) => {
      const claim = alert.kind === 'overdue'
        ? await tx.dentalCase.updateMany({ where: { id: c.id, overdueNotifiedAt: null, status: { in: IN_LAB_STATUSES } }, data: { overdueNotifiedAt: at, atRiskNotifiedAt: c.atRiskNotifiedAt ?? at } })
        : await tx.dentalCase.updateMany({ where: { id: c.id, atRiskNotifiedAt: null, status: { in: IN_LAB_STATUSES } }, data: { atRiskNotifiedAt: at } });
      if (!claim.count) return; // another worker got there first, or the case moved on

      const lab = [...(await technicianRecipients(tx, c.technicianId)), ...(await recipients(tx, RECIPIENTS.deadlineWatchers))];
      const users = alert.kind === 'overdue' ? [...lab, ...(await recipients(tx, RECIPIENTS.intake))] : lab;
      result.notifications += await notifyUsers(tx, users, alert.content, { caseId: c.id });
      result[alert.kind === 'overdue' ? 'overdue' : 'atRisk'] += 1;
    });
  }
  return result;
}

/** Housekeeping: drop sessions and reset tokens that ended more than a week ago. */
export async function purgeExpiredCredentials(now = Date.now()) {
  const before = new Date(now - 7 * 24 * HOUR_MS);
  const [sessions, tokens] = await prisma.$transaction([
    prisma.session.deleteMany({ where: { OR: [{ expiresAt: { lt: before } }, { revokedAt: { lt: before } }] } }),
    prisma.passwordResetToken.deleteMany({ where: { expiresAt: { lt: before } } }),
  ]);
  return { sessions: sessions.count, resetTokens: tokens.count };
}
