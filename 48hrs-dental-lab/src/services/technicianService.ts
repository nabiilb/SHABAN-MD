import { api } from './api/client';
import type { Technician } from '@/types/models';
import type { DirectoryListParams, Paginated, TechnicianDetail, TechnicianListItem, TechnicianPayload } from '@/types/api';

export const technicianService = {
  list: (params: DirectoryListParams & { active?: boolean }) =>
    api.get<Paginated<TechnicianListItem>>('/technicians', { params: { ...params } }),
  get: (id: string) => api.get<TechnicianDetail>(`/technicians/${id}`),
  create: (payload: TechnicianPayload) => api.post<Technician>('/technicians', payload),
  update: (id: string, payload: TechnicianPayload) => api.put<Technician>(`/technicians/${id}`, payload),
};
