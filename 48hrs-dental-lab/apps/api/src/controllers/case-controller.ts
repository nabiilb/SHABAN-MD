import type { NextFunction, Request, Response } from 'express';
import { caseNoteSchema, CASE_TYPES, createCaseSchema, PAYMENT_STATUSES, PRIORITIES, updateCaseSchema } from '@48hrs/shared/schemas';
import type { SlaFilter } from '@48hrs/shared/types';
import { ALL_STATUSES, type CaseActionEndpoint } from '@48hrs/shared/workflow';
import { authOf } from '../middleware/auth.ts';
import { parseBody } from '../middleware/validate.ts';
import { CASE_SORTS, type CaseSort } from '../repositories/case-repository.ts';
import { attachmentService, findVisibleCase } from '../services/attachment-service.ts';
import { caseService } from '../services/case-service.ts';
import { caseWorkflowService } from '../services/case-workflow-service.ts';
import { pageRequest, qBool, qDay, qEnum, qEnumList, qStr, sortDir } from '../utils/query.ts';

const SLA_FILTERS: SlaFilter[] = ['on_track', 'at_risk', 'overdue', 'due_today'];
const id = (req: Request) => String(req.params.id);

export const caseController = {
  async list(req: Request, res: Response) {
    const q = req.query;
    const sort = (qEnum(q, 'sort', CASE_SORTS) ?? 'receivedAt') as CaseSort;
    const filters = {
      search: qStr(q, 'search'),
      status: qEnumList(q, 'status', ALL_STATUSES),
      priority: qEnumList(q, 'priority', PRIORITIES),
      technicianId: qStr(q, 'technicianId'),
      doctorId: qStr(q, 'doctorId'),
      clinicId: qStr(q, 'clinicId'),
      patientId: qStr(q, 'patientId'),
      caseType: qEnum(q, 'caseType', CASE_TYPES),
      paymentStatus: qEnum(q, 'paymentStatus', PAYMENT_STATUSES),
      sla: qEnum(q, 'sla', SLA_FILTERS),
      from: qDay(q, 'from'),
      to: qDay(q, 'to'),
      dueFrom: qDay(q, 'dueFrom'),
      dueTo: qDay(q, 'dueTo'),
      openOnly: qBool(q, 'openOnly'),
    };
    res.json(await caseService.list(authOf(req), filters, pageRequest(q), sort, sortDir(q)));
  },

  async counts(req: Request, res: Response) {
    res.json(await caseService.counts(authOf(req)));
  },

  async get(req: Request, res: Response) {
    res.json(await caseService.get(authOf(req), id(req)));
  },

  async create(req: Request, res: Response) {
    res.status(201).json(await caseService.create(authOf(req), parseBody(createCaseSchema, req.body)));
  },

  async update(req: Request, res: Response) {
    res.json(await caseService.update(authOf(req), id(req), parseBody(updateCaseSchema, req.body)));
  },

  async remove(req: Request, res: Response) {
    await caseService.remove(authOf(req), id(req));
    res.status(204).end();
  },

  /** POST /cases/:id/status | assign | qc | rework | delivery */
  workflow(endpoint: CaseActionEndpoint) {
    return async (req: Request, res: Response) => {
      res.json(await caseWorkflowService.perform(authOf(req), id(req), endpoint, req.body));
    };
  },

  async addNote(req: Request, res: Response) {
    const { text } = parseBody(caseNoteSchema, req.body);
    res.status(201).json(await caseService.addNote(authOf(req), id(req), text));
  },

  /** Runs before multer, so nothing is written to disk for a case the caller cannot see. */
  async loadCaseForUpload(req: Request, res: Response, next: NextFunction) {
    res.locals.caseRef = await findVisibleCase(authOf(req), id(req));
    next();
  },

  async upload(req: Request, res: Response) {
    const caseRef = res.locals.caseRef as { id: string; caseNumber: string };
    res.status(201).json(await attachmentService.upload(authOf(req), caseRef, req.file, (req.body as { category?: unknown } | undefined)?.category));
  },

  async removeAttachment(req: Request, res: Response) {
    await attachmentService.remove(authOf(req), id(req), String(req.params.attachmentId));
    res.status(204).end();
  },

  async download(req: Request, res: Response) {
    const file = await attachmentService.open(authOf(req), id(req), String(req.params.attachmentId));
    res.setHeader('Content-Type', file.mimeType);
    res.setHeader('Content-Length', String(file.size));
    res.setHeader('Content-Disposition', `attachment; filename="${file.name.replace(/[^\x20-\x7e]/g, '_').replace(/"/g, '')}"; filename*=UTF-8''${encodeURIComponent(file.name)}`);
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Cache-Control', 'private, no-store');
    file.stream.on('error', () => res.destroy());
    file.stream.pipe(res);
  },
};
