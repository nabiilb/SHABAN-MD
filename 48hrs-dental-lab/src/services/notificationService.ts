import { api } from './api/client';
import type { AppNotification } from '@/types/models';
import type { Paginated } from '@/types/api';

export type NotificationList = Paginated<AppNotification> & { unreadCount: number };

export const notificationService = {
  list: (params: { page?: number; perPage?: number; unreadOnly?: boolean }) =>
    api.get<NotificationList>('/notifications', { params: { ...params } }),
  markRead: (id: string) => api.post<null>(`/notifications/${id}/read`),
  markAllRead: () => api.post<null>('/notifications/read-all'),
};
