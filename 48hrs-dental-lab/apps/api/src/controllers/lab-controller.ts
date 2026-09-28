import type { Request, Response } from 'express';
import { parsePeriod } from '@48hrs/shared/analytics';
import { CASE_TYPES, DELIVERY_METHODS } from '@48hrs/shared/schemas';
import type { DeliveryStatus, ReportFilters } from '@48hrs/shared/types';
import { ALL_STATUSES } from '@48hrs/shared/workflow';
import { authOf } from '../middleware/auth.ts';
import { analyticsService } from '../services/analytics-service.ts';
import { labService } from '../services/lab-service.ts';
import { notificationService } from '../services/notification-service.ts';
import { pageRequest, qBool, qEnum, qStr, sortDir, type Query } from '../utils/query.ts';

const DELIVERY_STATUSES: DeliveryStatus[] = ['ready', 'out_for_delivery', 'delivered'];

function reportFilters(q: Query): ReportFilters {
  return {
    from: qStr(q, 'from') ?? '',
    to: qStr(q, 'to') ?? '',
    technicianId: qStr(q, 'technicianId'),
    doctorId: qStr(q, 'doctorId'),
    clinicId: qStr(q, 'clinicId'),
    status: qEnum(q, 'status', ALL_STATUSES),
    caseType: qEnum(q, 'caseType', CASE_TYPES),
  };
}

export const labController = {
  async qualityChecks(req: Request, res: Response) {
    const q = req.query;
    res.json(await labService.qualityChecks(authOf(req), { search: qStr(q, 'search'), result: qEnum(q, 'result', ['passed', 'failed'] as const), technicianId: qStr(q, 'technicianId'), sort: qStr(q, 'sort'), dir: sortDir(q) }, pageRequest(q)));
  },

  async deliveries(req: Request, res: Response) {
    const q = req.query;
    res.json(await labService.deliveries(authOf(req), { search: qStr(q, 'search'), status: qEnum(q, 'status', DELIVERY_STATUSES), method: qEnum(q, 'method', DELIVERY_METHODS), sort: qStr(q, 'sort'), dir: sortDir(q) }, pageRequest(q)));
  },
};

export const analyticsController = {
  dashboard: async (req: Request, res: Response) => void res.json(await analyticsService.dashboard(authOf(req), parsePeriod(qStr(req.query, 'period')))),
  cases: async (req: Request, res: Response) => void res.json(await analyticsService.caseReport(authOf(req), reportFilters(req.query))),
  production: async (req: Request, res: Response) => void res.json(await analyticsService.productionReport(authOf(req), reportFilters(req.query))),
  technicians: async (req: Request, res: Response) => void res.json(await analyticsService.technicianReport(authOf(req), reportFilters(req.query))),
  clinics: async (req: Request, res: Response) => void res.json(await analyticsService.clinicReport(authOf(req), reportFilters(req.query))),
  financial: async (req: Request, res: Response) => void res.json(await analyticsService.financialReport(authOf(req), reportFilters(req.query))),
  search: async (req: Request, res: Response) => void res.json(await analyticsService.search(authOf(req), qStr(req.query, 'q') ?? '')),
};

export const notificationController = {
  list: async (req: Request, res: Response) => void res.json(await notificationService.list(authOf(req), qBool(req.query, 'unreadOnly') ?? false, pageRequest(req.query))),
  markRead: async (req: Request, res: Response) => {
    await notificationService.markRead(authOf(req), String(req.params.id));
    res.status(204).end();
  },
  markAllRead: async (req: Request, res: Response) => {
    await notificationService.markAllRead(authOf(req));
    res.status(204).end();
  },
};
