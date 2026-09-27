import { api } from './api/client';
import type { ActivityLogEntry, LabService, LabSettings, Permission, Role, RoleKey, User } from '@/types/models';
import type { ListParams, Paginated, ServicePayload, UserListParams, UserPayload } from '@/types/api';

export const userService = {
  list: (params: UserListParams) => api.get<Paginated<User>>('/users', { params: { ...params } }),
  create: (payload: UserPayload) => api.post<User>('/users', payload),
  update: (id: string, payload: UserPayload) => api.put<User>(`/users/${id}`, payload),
  setActive: (id: string, active: boolean) => api.patch<User>(`/users/${id}/status`, { active }),
  remove: (id: string) => api.delete<null>(`/users/${id}`),
};

export const roleService = {
  list: () => api.get<Role[]>('/roles'),
  permissions: () => api.get<Permission[]>('/permissions'),
  update: (key: RoleKey, permissions: string[]) => api.put<Role>(`/roles/${key}`, { permissions }),
};

export const catalogueService = {
  list: (params: { includeInactive?: boolean } = {}) => api.get<LabService[]>('/services', { params }),
  create: (payload: ServicePayload) => api.post<LabService>('/services', payload),
  update: (id: string, payload: ServicePayload) => api.put<LabService>(`/services/${id}`, payload),
  remove: (id: string) => api.delete<null>(`/services/${id}`),
};

export const settingsService = {
  get: () => api.get<LabSettings>('/settings'),
  update: (payload: LabSettings) => api.put<LabSettings>('/settings', payload),
  /** Mock API only: restores the seeded demo dataset. */
  resetDemo: () => api.post<null>('/settings/reset-demo'),
};

export const activityService = {
  list: (params: ListParams & { subjectType?: string }) =>
    api.get<Paginated<ActivityLogEntry>>('/activity', { params: { ...params } }),
};
