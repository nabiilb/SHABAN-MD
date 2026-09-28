import type { Request, Response } from 'express';
import { clinicSchema, doctorSchema, patientSchema, RECORD_STATUSES, technicianSchema } from '@48hrs/shared/schemas';
import { authOf } from '../middleware/auth.ts';
import { parseBody } from '../middleware/validate.ts';
import { clinicService, doctorService, patientService, technicianService, type DirectoryQuery } from '../services/directory-service.ts';
import { pageRequest, qBool, qEnum, qStr, sortDir } from '../utils/query.ts';

function directoryQuery(req: Request, defaultDir: 'asc' | 'desc'): DirectoryQuery {
  const q = req.query;
  return {
    search: qStr(q, 'search'),
    status: qEnum(q, 'status', RECORD_STATUSES),
    clinicId: qStr(q, 'clinicId'),
    active: qBool(q, 'active'),
    sort: qStr(q, 'sort'),
    dir: qStr(q, 'dir') ? sortDir(q) : defaultDir,
  };
}

const id = (req: Request) => String(req.params.id);

export const patientController = {
  list: async (req: Request, res: Response) => void res.json(await patientService.list(authOf(req), directoryQuery(req, 'desc'), pageRequest(req.query))),
  get: async (req: Request, res: Response) => void res.json(await patientService.get(authOf(req), id(req))),
  create: async (req: Request, res: Response) => void res.status(201).json(await patientService.create(authOf(req), parseBody(patientSchema, req.body))),
  update: async (req: Request, res: Response) => void res.json(await patientService.update(authOf(req), id(req), parseBody(patientSchema, req.body))),
  remove: async (req: Request, res: Response) => {
    await patientService.remove(authOf(req), id(req));
    res.status(204).end();
  },
};

export const doctorController = {
  list: async (req: Request, res: Response) => void res.json(await doctorService.list(authOf(req), directoryQuery(req, 'asc'), pageRequest(req.query))),
  get: async (req: Request, res: Response) => void res.json(await doctorService.get(authOf(req), id(req))),
  create: async (req: Request, res: Response) => void res.status(201).json(await doctorService.create(authOf(req), parseBody(doctorSchema, req.body))),
  update: async (req: Request, res: Response) => void res.json(await doctorService.update(authOf(req), id(req), parseBody(doctorSchema, req.body))),
  remove: async (req: Request, res: Response) => {
    await doctorService.remove(authOf(req), id(req));
    res.status(204).end();
  },
};

export const clinicController = {
  list: async (req: Request, res: Response) => void res.json(await clinicService.list(authOf(req), directoryQuery(req, 'asc'), pageRequest(req.query))),
  get: async (req: Request, res: Response) => void res.json(await clinicService.get(authOf(req), id(req))),
  create: async (req: Request, res: Response) => void res.status(201).json(await clinicService.create(authOf(req), parseBody(clinicSchema, req.body))),
  update: async (req: Request, res: Response) => void res.json(await clinicService.update(authOf(req), id(req), parseBody(clinicSchema, req.body))),
  remove: async (req: Request, res: Response) => {
    await clinicService.remove(authOf(req), id(req));
    res.status(204).end();
  },
};

export const technicianController = {
  list: async (req: Request, res: Response) => void res.json(await technicianService.list(directoryQuery(req, 'asc'), pageRequest(req.query))),
  get: async (req: Request, res: Response) => void res.json(await technicianService.get(authOf(req), id(req))),
  create: async (req: Request, res: Response) => void res.status(201).json(await technicianService.create(authOf(req), parseBody(technicianSchema, req.body))),
  update: async (req: Request, res: Response) => void res.json(await technicianService.update(authOf(req), id(req), parseBody(technicianSchema, req.body))),
  remove: async (req: Request, res: Response) => {
    await technicianService.remove(authOf(req), id(req));
    res.status(204).end();
  },
};
