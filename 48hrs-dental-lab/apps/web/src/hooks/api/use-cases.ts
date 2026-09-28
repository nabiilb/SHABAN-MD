import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { qk } from '@/lib/query-keys';
import { caseService } from '@/services/caseService';
import type { CaseActionPayload, CaseListParams, CreateCasePayload, UpdateCasePayload } from '@48hrs/shared/types';
import type { CaseDetail } from '@48hrs/shared/types';

export function useCases(params: CaseListParams, opts: { enabled?: boolean; refetchInterval?: number } = {}) {
  return useQuery({
    queryKey: qk.cases.list(params),
    queryFn: ({ signal }) => caseService.list(params, signal),
    placeholderData: keepPreviousData,
    enabled: opts.enabled,
    refetchInterval: opts.refetchInterval,
  });
}

export function useCase(id: string | undefined) {
  return useQuery({ queryKey: qk.cases.detail(id ?? ''), queryFn: () => caseService.get(id!), enabled: !!id });
}

export function useNavCounts(enabled = true) {
  return useQuery({ queryKey: qk.cases.counts, queryFn: caseService.counts, enabled, refetchInterval: 60_000 });
}

/** After any case change, refresh every view that aggregates cases. */
export function useInvalidateCaseViews() {
  const qc = useQueryClient();
  return (detail?: CaseDetail) => {
    if (detail) qc.setQueryData(qk.cases.detail(detail.id), detail);
    void qc.invalidateQueries({ queryKey: qk.cases.all });
    void qc.invalidateQueries({ queryKey: qk.dashboard });
    void qc.invalidateQueries({ queryKey: qk.notifications.all });
    void qc.invalidateQueries({ queryKey: qk.invoices.all });
    void qc.invalidateQueries({ queryKey: qk.payments.all });
    void qc.invalidateQueries({ queryKey: qk.qc.all });
    void qc.invalidateQueries({ queryKey: qk.deliveries.all });
    void qc.invalidateQueries({ queryKey: qk.technicians.all });
    void qc.invalidateQueries({ queryKey: ['reports'] });
  };
}

export function useCreateCase() {
  const invalidate = useInvalidateCaseViews();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateCasePayload) => caseService.create(payload),
    onSuccess: (detail) => {
      invalidate(detail);
      void qc.invalidateQueries({ queryKey: qk.patients.all });
    },
  });
}

export function useUpdateCase(id: string) {
  const invalidate = useInvalidateCaseViews();
  return useMutation({ mutationFn: (payload: UpdateCasePayload) => caseService.update(id, payload), onSuccess: invalidate });
}

export function useDeleteCase() {
  const invalidate = useInvalidateCaseViews();
  return useMutation({ mutationFn: (id: string) => caseService.remove(id), onSuccess: () => invalidate() });
}

export function useCaseAction() {
  const invalidate = useInvalidateCaseViews();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: CaseActionPayload }) => caseService.action(id, payload),
    onSuccess: invalidate,
  });
}

export function useAddCaseNote(id: string) {
  const invalidate = useInvalidateCaseViews();
  return useMutation({ mutationFn: (text: string) => caseService.addNote(id, text), onSuccess: invalidate });
}

export function useDeleteAttachment(caseId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (attachmentId: string) => caseService.deleteAttachment(caseId, attachmentId),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: qk.cases.detail(caseId) });
      void qc.invalidateQueries({ queryKey: qk.cases.all });
    },
  });
}
