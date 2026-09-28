/** A user's own notifications. Other people's notifications are invisible (404). */
import type { AppNotification, Paginated } from '@48hrs/shared/types';
import { notFound } from '../lib/errors.ts';
import { prisma } from '../lib/prisma.ts';
import { notificationInclude, toNotification } from '../repositories/mappers.ts';
import type { AuthContext } from '../types/auth.ts';
import { paginate, type PageRequest } from '../utils/query.ts';

export const notificationService = {
  async list(auth: AuthContext, unreadOnly: boolean, page: PageRequest): Promise<Paginated<AppNotification> & { unreadCount: number }> {
    const mine = { userId: auth.user.id };
    const where = unreadOnly ? { ...mine, readAt: null } : mine;
    const [total, unreadCount] = await Promise.all([prisma.notification.count({ where }), prisma.notification.count({ where: { ...mine, readAt: null } })]);
    const { skip, take, meta } = paginate(page, total);
    const rows = await prisma.notification.findMany({ where, orderBy: [{ createdAt: 'desc' }, { id: 'desc' }], skip, take, include: notificationInclude });
    return { data: rows.map(toNotification), meta, unreadCount };
  },

  async markRead(auth: AuthContext, id: string) {
    const { count } = await prisma.notification.updateMany({ where: { id, userId: auth.user.id, readAt: null }, data: { readAt: new Date() } });
    if (!count && !(await prisma.notification.findFirst({ where: { id, userId: auth.user.id }, select: { id: true } }))) throw notFound();
  },

  async markAllRead(auth: AuthContext) {
    await prisma.notification.updateMany({ where: { userId: auth.user.id, readAt: null }, data: { readAt: new Date() } });
  },
};
