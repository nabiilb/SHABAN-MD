import { api } from './api/client';
import type { UploadProgress } from './api/types';
import type { CaseAttachment, CaseDetail, CaseListItem } from '@/types/models';
import type {
  CaseActionPayload,
  CaseListParams,
  CreateCasePayload,
  NavCounts,
  Paginated,
  UpdateCasePayload,
  UploadAttachmentPayload,
} from '@/types/api';

export const caseService = {
  list: (params: CaseListParams, signal?: AbortSignal) =>
    api.get<Paginated<CaseListItem>>('/cases', { params: { ...params }, signal }),
  get: (id: string) => api.get<CaseDetail>(`/cases/${id}`),
  create: (payload: CreateCasePayload) => api.post<CaseDetail>('/cases', payload),
  update: (id: string, payload: UpdateCasePayload) => api.patch<CaseDetail>(`/cases/${id}`, payload),
  remove: (id: string) => api.delete<null>(`/cases/${id}`),
  /** Every status change is a named workflow action validated by the server. */
  action: (id: string, payload: CaseActionPayload) => api.post<CaseDetail>(`/cases/${id}/actions`, payload),
  addNote: (id: string, text: string) => api.post<CaseDetail>(`/cases/${id}/notes`, { text }),
  counts: () => api.get<NavCounts>('/cases/counts'),

  uploadAttachment: (id: string, payload: UploadAttachmentPayload, onProgress?: (p: UploadProgress) => void, signal?: AbortSignal) => {
    const fd = new FormData();
    fd.append('file', payload.file);
    fd.append('category', payload.category);
    return api.upload<CaseAttachment>(`/cases/${id}/attachments`, fd, onProgress, signal);
  },
  deleteAttachment: (id: string, attachmentId: string) => api.delete<null>(`/cases/${id}/attachments/${attachmentId}`),
  downloadAttachment: (id: string, attachmentId: string) => api.download(`/cases/${id}/attachments/${attachmentId}/download`),
};
