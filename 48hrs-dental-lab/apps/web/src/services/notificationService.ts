import { api } from './api/client';
import type { AppNotification } from '@48hrs/shared/types';
import type { Paginated } from '@48hrs/shared/types';

export type NotificationList = Paginated<AppNotification> & { unreadCount: number };

export const notificationService = {
  list: (params: { page?: number; perPage?: number; unreadOnly?: boolean }) =>
    api.get<NotificationList>('/notifications', { params: { ...params } }),
  markRead: (id: string) => api.post<null>(`/notifications/${id}/read`),
  markAllRead: () => api.post<null>('/notifications/read-all'),
};
