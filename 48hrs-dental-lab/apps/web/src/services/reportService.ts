import { combineReport } from '@48hrs/shared/analytics';
import { api } from './api/client';
import type {
  CaseReport,
  ClinicReport,
  DashboardPeriod,
  DashboardSummary,
  FinancialReport,
  ProductionReport,
  ReportFilters,
  ReportResult,
  SearchResult,
  TechnicianReport,
} from '@48hrs/shared/types';

export const reportService = {
  cases: (f: ReportFilters) => api.get<CaseReport>('/reports/cases', { params: { ...f } }),
  production: (f: ReportFilters) => api.get<ProductionReport>('/reports/production', { params: { ...f } }),
  technicians: (f: ReportFilters) => api.get<TechnicianReport>('/reports/technicians', { params: { ...f } }),
  clinics: (f: ReportFilters) => api.get<ClinicReport>('/reports/clinics', { params: { ...f } }),
  /** Requires reports.financial. */
  financial: (f: ReportFilters) => api.get<FinancialReport>('/reports/financial', { params: { ...f } }),
  /** Every report section for one set of filters, merged for the Reports page. */
  run: async (filters: ReportFilters, { financial = false }: { financial?: boolean } = {}): Promise<ReportResult> => {
    const [cases, production, technicians, clinics, money] = await Promise.all([
      reportService.cases(filters),
      reportService.production(filters),
      reportService.technicians(filters),
      reportService.clinics(filters),
      financial ? reportService.financial(filters) : Promise.resolve(null),
    ]);
    return combineReport({ cases, production, technicians, clinics, financial: money });
  },
  dashboard: (period: DashboardPeriod = '30d') => api.get<DashboardSummary>('/dashboard', { params: { period } }),
};

export const searchService = {
  search: (q: string, signal?: AbortSignal) => api.get<SearchResult[]>('/search', { params: { q }, signal }),
};
