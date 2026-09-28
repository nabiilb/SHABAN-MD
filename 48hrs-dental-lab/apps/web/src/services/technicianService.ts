import { api } from './api/client';
import type { Technician } from '@48hrs/shared/types';
import type { DirectoryListParams, Paginated, TechnicianDetail, TechnicianListItem, TechnicianPayload } from '@48hrs/shared/types';

export const technicianService = {
  list: (params: DirectoryListParams & { active?: boolean }) =>
    api.get<Paginated<TechnicianListItem>>('/technicians', { params: { ...params } }),
  get: (id: string) => api.get<TechnicianDetail>(`/technicians/${id}`),
  create: (payload: TechnicianPayload) => api.post<Technician>('/technicians', payload),
  update: (id: string, payload: TechnicianPayload) => api.put<Technician>(`/technicians/${id}`, payload),
  remove: (id: string) => api.delete<null>(`/technicians/${id}`),
};
