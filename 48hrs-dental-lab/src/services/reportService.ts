import { api } from './api/client';
import type { DashboardPeriod, DashboardSummary, ReportFilters, ReportResult, SearchResult } from '@/types/api';

export const reportService = {
  run: (filters: ReportFilters) => api.get<ReportResult>('/reports', { params: { ...filters } }),
  dashboard: (period: DashboardPeriod = '30d') => api.get<DashboardSummary>('/dashboard', { params: { period } }),
};

export const searchService = {
  search: (q: string, signal?: AbortSignal) => api.get<SearchResult[]>('/search', { params: { q }, signal }),
};
