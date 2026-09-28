import { fromRequestErrors, toCaseRequest } from '@48hrs/shared/case-requests';
import { api } from './api/client';
import { ApiError } from './api/errors';
import type { UploadProgress } from './api/types';
import type { AttachmentCategory, CaseAttachment, CaseDetail, CaseListItem } from '@48hrs/shared/types';
import type {
  CaseActionPayload,
  CaseListParams,
  CreateCasePayload,
  NavCounts,
  Paginated,
  UpdateCasePayload,
} from '@48hrs/shared/types';

export interface UploadAttachmentPayload {
  file: File;
  category: AttachmentCategory;
}

export const caseService = {
  list: (params: CaseListParams, signal?: AbortSignal) =>
    api.get<Paginated<CaseListItem>>('/cases', { params: { ...params }, signal }),
  get: (id: string) => api.get<CaseDetail>(`/cases/${id}`),
  create: (payload: CreateCasePayload) => api.post<CaseDetail>('/cases', payload),
  update: (id: string, payload: UpdateCasePayload) => api.patch<CaseDetail>(`/cases/${id}`, payload),
  remove: (id: string) => api.delete<null>(`/cases/${id}`),
  /**
   * Every status change is a named workflow action, sent to its REST sub-resource
   * (POST /cases/:id/status | assign | qc | rework | delivery). 422 field keys are
   * translated back to action-payload paths so the action dialog can show them.
   */
  action: async (id: string, payload: CaseActionPayload) => {
    const { endpoint, body } = toCaseRequest(payload);
    try {
      return await api.post<CaseDetail>(`/cases/${id}/${endpoint}`, body);
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) throw new ApiError(err.status, err.message, fromRequestErrors(endpoint, err.fieldErrors));
      throw err;
    }
  },
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
