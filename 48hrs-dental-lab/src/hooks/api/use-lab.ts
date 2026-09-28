import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { qk } from '@/lib/query-keys';
import { deliveryService, qcService } from '@/services/labService';
import { reportService, searchService } from '@/services/reportService';
import type { DashboardPeriod, ListParams, ReportFilters } from '@/types/api';

export const useQualityChecks = (p: ListParams & { result?: 'passed' | 'failed' }, enabled = true) =>
  useQuery({ queryKey: qk.qc.list(p), queryFn: () => qcService.list(p), placeholderData: keepPreviousData, enabled });

export const useDeliveries = (p: ListParams & { status?: string; method?: string }, enabled = true) =>
  useQuery({ queryKey: qk.deliveries.list(p), queryFn: () => deliveryService.list(p), placeholderData: keepPreviousData, enabled });

export const useDashboard = (period: DashboardPeriod = '30d') =>
  useQuery({ queryKey: [...qk.dashboard, period], queryFn: () => reportService.dashboard(period), refetchInterval: 60_000, placeholderData: keepPreviousData });

export const useReport = (f: ReportFilters) => useQuery({ queryKey: qk.reports(f), queryFn: () => reportService.run(f), placeholderData: keepPreviousData });

export const useSearch = (q: string) =>
  useQuery({ queryKey: qk.search(q), queryFn: ({ signal }) => searchService.search(q, signal), enabled: q.trim().length >= 2, staleTime: 10_000 });
