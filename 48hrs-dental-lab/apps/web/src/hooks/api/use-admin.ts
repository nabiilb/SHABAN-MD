import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { qk } from '@/lib/query-keys';
import { activityService, catalogueService, roleService, settingsService, userService } from '@/services/adminService';
import { useAuthStore } from '@/stores/auth-store';
import { setCurrency } from '@/utils/format';
import type { LabSettings, RoleKey } from '@48hrs/shared/types';
import type { ListParams, ServicePayload, UserListParams, UserPayload } from '@48hrs/shared/types';

export const useUsers = (p: UserListParams) => useQuery({ queryKey: qk.users.list(p), queryFn: () => userService.list(p), placeholderData: keepPreviousData });

export function useSaveUser() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id?: string; payload: UserPayload }) => (id ? userService.update(id, payload) : userService.create(payload)),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: qk.users.all });
      void qc.invalidateQueries({ queryKey: qk.technicians.all });
    },
  });
}

export function useSetUserActive() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: ({ id, active }: { id: string; active: boolean }) => userService.setActive(id, active), onSuccess: () => void qc.invalidateQueries({ queryKey: qk.users.all }) });
}

export function useDeleteUser() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (id: string) => userService.remove(id), onSuccess: () => void qc.invalidateQueries({ queryKey: qk.users.all }) });
}

export const useRoles = () => useQuery({ queryKey: qk.roles, queryFn: roleService.list });
export const usePermissionCatalogue = () => useQuery({ queryKey: qk.permissions, queryFn: roleService.permissions, staleTime: Infinity });

export function useUpdateRole() {
  const qc = useQueryClient();
  const refresh = useAuthStore((s) => s.refresh);
  return useMutation({
    mutationFn: ({ key, permissions }: { key: RoleKey; permissions: string[] }) => roleService.update(key, permissions),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: qk.roles });
      void refresh();
    },
  });
}

export const useServices = (includeInactive = false) =>
  useQuery({ queryKey: qk.services(includeInactive), queryFn: () => catalogueService.list({ includeInactive }), staleTime: 5 * 60_000 });

export function useSaveService() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id?: string; payload: ServicePayload }) => (id ? catalogueService.update(id, payload) : catalogueService.create(payload)),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['services'] }),
  });
}

export function useDeleteService() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (id: string) => catalogueService.remove(id), onSuccess: () => void qc.invalidateQueries({ queryKey: ['services'] }) });
}

export function useSettings(enabled = true) {
  return useQuery({
    queryKey: qk.settings,
    queryFn: async () => {
      const s = await settingsService.get();
      setCurrency(s.currency);
      return s;
    },
    staleTime: 5 * 60_000,
    enabled,
  });
}

export function useUpdateSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: LabSettings) => settingsService.update(payload),
    onSuccess: (s) => {
      setCurrency(s.currency);
      qc.setQueryData(qk.settings, s);
      void qc.invalidateQueries();
    },
  });
}

export function useResetDemo() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: settingsService.resetDemo, onSuccess: () => void qc.invalidateQueries() });
}

export const useActivity = (p: ListParams & { subjectType?: string }) =>
  useQuery({ queryKey: qk.activity(p), queryFn: () => activityService.list(p), placeholderData: keepPreviousData });
