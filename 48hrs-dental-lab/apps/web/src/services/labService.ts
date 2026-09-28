import { api } from './api/client';
import type { DeliveryListItem, Paginated, QualityCheckListItem, ListParams } from '@48hrs/shared/types';

/** Quality-control and delivery history (the live queues come from caseService.list). */
export const qcService = {
  list: (params: ListParams & { result?: 'passed' | 'failed'; technicianId?: string }) =>
    api.get<Paginated<QualityCheckListItem>>('/quality-control', { params: { ...params } }),
};

export const deliveryService = {
  list: (params: ListParams & { status?: string; method?: string }) =>
    api.get<Paginated<DeliveryListItem>>('/deliveries', { params: { ...params } }),
};
