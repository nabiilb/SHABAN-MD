import type { Request, Response } from 'express';
import { createInvoiceSchema, PAYMENT_METHODS, PAYMENT_STATUSES, recordPaymentSchema } from '@48hrs/shared/schemas';
import { authOf } from '../middleware/auth.ts';
import { parseBody } from '../middleware/validate.ts';
import { financeService } from '../services/finance-service.ts';
import { pageRequest, qDay, qEnum, qStr, sortDir } from '../utils/query.ts';

export const financeController = {
  async listInvoices(req: Request, res: Response) {
    const q = req.query;
    res.json(
      await financeService.listInvoices(
        authOf(req),
        { search: qStr(q, 'search'), status: qEnum(q, 'status', PAYMENT_STATUSES), clinicId: qStr(q, 'clinicId'), doctorId: qStr(q, 'doctorId'), from: qDay(q, 'from'), to: qDay(q, 'to'), sort: qStr(q, 'sort'), dir: sortDir(q) },
        pageRequest(q),
      ),
    );
  },

  async getInvoice(req: Request, res: Response) {
    res.json(await financeService.getInvoice(authOf(req), String(req.params.id)));
  },

  async createInvoice(req: Request, res: Response) {
    const { caseId } = parseBody(createInvoiceSchema, req.body);
    res.status(201).json(await financeService.createInvoice(authOf(req), caseId));
  },

  async listPayments(req: Request, res: Response) {
    const q = req.query;
    res.json(
      await financeService.listPayments(
        authOf(req),
        { search: qStr(q, 'search'), method: qEnum(q, 'method', PAYMENT_METHODS), clinicId: qStr(q, 'clinicId'), from: qDay(q, 'from'), to: qDay(q, 'to'), sort: qStr(q, 'sort'), dir: sortDir(q) },
        pageRequest(q),
      ),
    );
  },

  /** Returns the updated invoice so the client shows the new balance straight away. */
  async recordPayment(req: Request, res: Response) {
    res.status(201).json(await financeService.recordPayment(authOf(req), parseBody(recordPaymentSchema, req.body)));
  },
};
