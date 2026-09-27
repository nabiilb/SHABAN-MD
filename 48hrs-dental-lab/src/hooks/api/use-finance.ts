import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { qk } from '@/lib/query-keys';
import { invoiceService, paymentService } from '@/services/paymentService';
import type { InvoiceListParams, PaymentListParams, RecordPaymentPayload } from '@/types/api';

export const useInvoices = (p: InvoiceListParams) =>
  useQuery({ queryKey: qk.invoices.list(p), queryFn: () => invoiceService.list(p), placeholderData: keepPreviousData });
export const useInvoice = (id?: string) => useQuery({ queryKey: qk.invoices.detail(id ?? ''), queryFn: () => invoiceService.get(id!), enabled: !!id });
export const usePayments = (p: PaymentListParams) =>
  useQuery({ queryKey: qk.payments.list(p), queryFn: () => paymentService.list(p), placeholderData: keepPreviousData });

export function useRecordPayment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ invoiceId, payload }: { invoiceId: string; payload: RecordPaymentPayload }) => paymentService.record(invoiceId, payload),
    onSuccess: (inv) => {
      qc.setQueryData(qk.invoices.detail(inv.id), inv);
      void qc.invalidateQueries({ queryKey: qk.invoices.all });
      void qc.invalidateQueries({ queryKey: qk.payments.all });
      void qc.invalidateQueries({ queryKey: qk.cases.all });
      void qc.invalidateQueries({ queryKey: qk.dashboard });
      void qc.invalidateQueries({ queryKey: qk.clinics.all });
    },
  });
}
