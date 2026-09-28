import { api } from './api/client';
import type { Doctor } from '@48hrs/shared/types';
import type { DirectoryListParams, DoctorDetail, DoctorListItem, DoctorPayload, Paginated } from '@48hrs/shared/types';

export const doctorService = {
  list: (params: DirectoryListParams) => api.get<Paginated<DoctorListItem>>('/doctors', { params: { ...params } }),
  get: (id: string) => api.get<DoctorDetail>(`/doctors/${id}`),
  create: (payload: DoctorPayload) => api.post<Doctor>('/doctors', payload),
  update: (id: string, payload: DoctorPayload) => api.put<Doctor>(`/doctors/${id}`, payload),
  remove: (id: string) => api.delete<null>(`/doctors/${id}`),
};
