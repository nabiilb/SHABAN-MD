import type { Request, Response } from 'express';
import { ROLE_KEYS, rolePermissionsSchema, serviceSchema, settingsSchema, userSchema, userStatusSchema } from '@48hrs/shared/schemas';
import { authOf } from '../middleware/auth.ts';
import { parseBody } from '../middleware/validate.ts';
import { activityService, catalogueService, roleService, settingsService, userService } from '../services/admin-service.ts';
import { pageRequest, qBool, qEnum, qStr, sortDir } from '../utils/query.ts';

const id = (req: Request) => String(req.params.id);

export const userController = {
  async list(req: Request, res: Response) {
    const q = req.query;
    res.json(await userService.list({ search: qStr(q, 'search'), role: qEnum(q, 'role', ROLE_KEYS), active: qBool(q, 'active'), sort: qStr(q, 'sort'), dir: qStr(q, 'dir') ? sortDir(q) : 'asc' }, pageRequest(q)));
  },
  create: async (req: Request, res: Response) => void res.status(201).json(await userService.create(authOf(req), parseBody(userSchema, req.body))),
  update: async (req: Request, res: Response) => void res.json(await userService.update(authOf(req), id(req), parseBody(userSchema, req.body))),
  setStatus: async (req: Request, res: Response) => void res.json(await userService.setActive(authOf(req), id(req), parseBody(userStatusSchema, req.body).active)),
  remove: async (req: Request, res: Response) => {
    await userService.remove(authOf(req), id(req));
    res.status(204).end();
  },
};

export const roleController = {
  list: async (_req: Request, res: Response) => void res.json(await roleService.list()),
  permissions: async (_req: Request, res: Response) => void res.json(await roleService.permissions()),
  update: async (req: Request, res: Response) => void res.json(await roleService.update(authOf(req), String(req.params.key), parseBody(rolePermissionsSchema, req.body).permissions)),
};

export const catalogueController = {
  list: async (req: Request, res: Response) => void res.json(await catalogueService.list(qBool(req.query, 'includeInactive') ?? false)),
  create: async (req: Request, res: Response) => void res.status(201).json(await catalogueService.create(authOf(req), parseBody(serviceSchema, req.body))),
  update: async (req: Request, res: Response) => void res.json(await catalogueService.update(authOf(req), id(req), parseBody(serviceSchema, req.body))),
  remove: async (req: Request, res: Response) => {
    await catalogueService.remove(authOf(req), id(req));
    res.status(204).end();
  },
};

export const settingsController = {
  get: async (_req: Request, res: Response) => void res.json(await settingsService.get()),
  update: async (req: Request, res: Response) => void res.json(await settingsService.update(authOf(req), parseBody(settingsSchema, req.body))),
};

export const activityController = {
  list: async (req: Request, res: Response) => void res.json(await activityService.list({ search: qStr(req.query, 'search'), subjectType: qStr(req.query, 'subjectType') }, pageRequest(req.query))),
};
