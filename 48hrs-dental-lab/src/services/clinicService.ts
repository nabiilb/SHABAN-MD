import { api } from './api/client';
import type { Clinic } from '@/types/models';
import type { ClinicDetail, ClinicListItem, ClinicPayload, DirectoryListParams, Paginated } from '@/types/api';

export const clinicService = {
  list: (params: DirectoryListParams) => api.get<Paginated<ClinicListItem>>('/clinics', { params: { ...params } }),
  get: (id: string) => api.get<ClinicDetail>(`/clinics/${id}`),
  create: (payload: ClinicPayload) => api.post<Clinic>('/clinics', payload),
  update: (id: string, payload: ClinicPayload) => api.put<Clinic>(`/clinics/${id}`, payload),
  remove: (id: string) => api.delete<null>(`/clinics/${id}`),
};
