import { api } from './api/client';
import type { InvoiceDetail, InvoiceListItem, PaymentListItem } from '@/types/models';
import type { InvoiceListParams, Paginated, PaymentListParams, RecordPaymentPayload } from '@/types/api';

export const invoiceService = {
  list: (params: InvoiceListParams) => api.get<Paginated<InvoiceListItem>>('/invoices', { params: { ...params } }),
  get: (id: string) => api.get<InvoiceDetail>(`/invoices/${id}`),
};

export const paymentService = {
  list: (params: PaymentListParams) => api.get<Paginated<PaymentListItem>>('/payments', { params: { ...params } }),
  record: (invoiceId: string, payload: RecordPaymentPayload) =>
    api.post<InvoiceDetail>(`/invoices/${invoiceId}/payments`, payload),
};
