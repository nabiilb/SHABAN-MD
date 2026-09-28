import type { ActivityLogEntry } from '@48hrs/shared/types';
import type { DbOrTx } from '../lib/prisma.ts';

export type ActivityInput = Pick<ActivityLogEntry, 'action' | 'description' | 'subjectType'> & Partial<Pick<ActivityLogEntry, 'subjectId' | 'subjectLabel'>>;

/** Appends to the audit trail (inside the caller's transaction when given one). */
export async function logActivity(db: DbOrTx, user: { id: string; name: string }, entry: ActivityInput) {
  await db.activityLog.create({
    data: {
      userId: user.id,
      userName: user.name,
      action: entry.action,
      description: entry.description,
      subjectType: entry.subjectType,
      subjectId: entry.subjectId ?? null,
      subjectLabel: entry.subjectLabel ?? null,
    },
  });
}
