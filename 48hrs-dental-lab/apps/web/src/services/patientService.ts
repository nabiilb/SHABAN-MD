import { api } from './api/client';
import type { Patient } from '@48hrs/shared/types';
import type { DirectoryListParams, Paginated, PatientDetail, PatientListItem, PatientPayload } from '@48hrs/shared/types';

export const patientService = {
  list: (params: DirectoryListParams) => api.get<Paginated<PatientListItem>>('/patients', { params: { ...params } }),
  get: (id: string) => api.get<PatientDetail>(`/patients/${id}`),
  create: (payload: PatientPayload) => api.post<Patient>('/patients', payload),
  update: (id: string, payload: PatientPayload) => api.put<Patient>(`/patients/${id}`, payload),
  remove: (id: string) => api.delete<null>(`/patients/${id}`),
};
