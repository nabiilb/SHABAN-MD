import { api } from './api/client';
import type { InvoiceDetail, InvoiceListItem, PaymentListItem } from '@48hrs/shared/types';
import type { CreateInvoicePayload, InvoiceListParams, Paginated, PaymentListParams, RecordPaymentPayload } from '@48hrs/shared/types';

export const invoiceService = {
  list: (params: InvoiceListParams) => api.get<Paginated<InvoiceListItem>>('/invoices', { params: { ...params } }),
  get: (id: string) => api.get<InvoiceDetail>(`/invoices/${id}`),
  /** Issues the invoice for a received case that has none. */
  create: (payload: CreateInvoicePayload) => api.post<InvoiceDetail>('/invoices', payload),
};

export const paymentService = {
  list: (params: PaymentListParams) => api.get<Paginated<PaymentListItem>>('/payments', { params: { ...params } }),
  record: (invoiceId: string, payload: Omit<RecordPaymentPayload, 'invoiceId'>) =>
    api.post<InvoiceDetail>('/payments', { ...payload, invoiceId } satisfies RecordPaymentPayload),
};
