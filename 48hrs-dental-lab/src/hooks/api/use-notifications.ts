import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { qk } from '@/lib/query-keys';
import { notificationService } from '@/services/notificationService';

export const useNotifications = (p: { page?: number; perPage?: number; unreadOnly?: boolean }, refetchInterval?: number) =>
  useQuery({ queryKey: qk.notifications.list(p), queryFn: () => notificationService.list(p), refetchInterval });

export function useMarkNotificationRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => notificationService.markRead(id),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: qk.notifications.all });
      void qc.invalidateQueries({ queryKey: qk.cases.counts });
    },
  });
}

export function useMarkAllNotificationsRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: notificationService.markAllRead,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: qk.notifications.all });
      void qc.invalidateQueries({ queryKey: qk.cases.counts });
    },
  });
}
