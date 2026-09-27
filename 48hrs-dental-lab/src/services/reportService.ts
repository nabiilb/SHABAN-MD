import { api } from './api/client';
import type { DashboardSummary, ReportFilters, ReportResult, SearchResult } from '@/types/api';

export const reportService = {
  run: (filters: ReportFilters) => api.get<ReportResult>('/reports', { params: { ...filters } }),
  dashboard: () => api.get<DashboardSummary>('/dashboard'),
};

export const searchService = {
  search: (q: string, signal?: AbortSignal) => api.get<SearchResult[]>('/search', { params: { q }, signal }),
};
