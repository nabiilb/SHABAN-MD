/**
 * Creates in-app notifications. Wording and recipient rules come from
 * packages/shared/src/notifications.ts; this module resolves them to users.
 */
import type { NotificationContent, RecipientRule } from '@48hrs/shared/notifications';
import type { DbOrTx } from '../lib/prisma.ts';

/** Active users of the rule's roles whose role currently grants the permission. */
export async function recipients(db: DbOrTx, rule: RecipientRule): Promise<string[]> {
  const users = await db.user.findMany({
    where: { active: true, roleKey: { in: rule.roles }, role: { permissions: { some: { permissionKey: rule.permission } } } },
    select: { id: true },
  });
  return users.map((u) => u.id);
}

/** The login linked to a technician profile, when active. */
export async function technicianRecipients(db: DbOrTx, technicianId: string | null | undefined): Promise<string[]> {
  if (!technicianId) return [];
  const t = await db.technician.findUnique({ where: { id: technicianId }, select: { user: { select: { id: true, active: true } } } });
  return t?.user?.active ? [t.user.id] : [];
}

/** Active clinic-portal users of a clinic. */
export async function clinicRecipients(db: DbOrTx, clinicId: string): Promise<string[]> {
  const users = await db.user.findMany({ where: { active: true, roleKey: 'client', clinicId }, select: { id: true } });
  return users.map((u) => u.id);
}

/** One notification per distinct user, never to the person who caused it. */
export async function notifyUsers(db: DbOrTx, userIds: string[], content: NotificationContent, opts: { caseId?: string | null; exceptUserId?: string } = {}) {
  const unique = [...new Set(userIds)].filter((id) => id !== opts.exceptUserId);
  if (!unique.length) return 0;
  const { count } = await db.notification.createMany({
    data: unique.map((userId) => ({ userId, type: content.type, title: content.title, message: content.message, caseId: opts.caseId ?? null })),
  });
  return count;
}
