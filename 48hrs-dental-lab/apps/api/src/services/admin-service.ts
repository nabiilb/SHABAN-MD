/** Users, roles & permissions, the service catalogue, lab settings and the activity log. */
import { ALL_PERMISSION_KEYS, PERMISSIONS, ROLE_LABELS } from '@48hrs/shared/permissions';
import type { serviceSchema, settingsSchema, userSchema } from '@48hrs/shared/schemas';
import type { ActivityLogEntry, LabService, LabSettings, Paginated, Permission, Role, RoleKey, User } from '@48hrs/shared/types';
import type { z } from 'zod';
import { Prisma } from '../generated/prisma/client.ts';
import { forbidden, notFound, throwIfErrors, unprocessable, validation, type FieldErrors } from '../lib/errors.ts';
import { hashPassword } from '../lib/password.ts';
import { prisma, type Tx } from '../lib/prisma.ts';
import { logActivity } from '../repositories/activity-repository.ts';
import { toActivity, toService, toUser, userInclude } from '../repositories/mappers.ts';
import { sortBy, type Direction } from '../repositories/ordering.ts';
import { readLabSettings, writeLabSettings } from '../repositories/settings-repository.ts';
import type { AuthContext } from '../types/auth.ts';
import { paginate, type PageRequest } from '../utils/query.ts';

type UserBody = z.output<typeof userSchema>;
type ServiceBody = z.output<typeof serviceSchema>;

const insensitive = (v: string) => ({ contains: v, mode: 'insensitive' as const });

export interface UserQuery {
  search?: string;
  role?: RoleKey;
  active?: boolean;
  sort?: string;
  dir: Direction;
}

/** Technician logins are always linked to a technician profile (created when missing). */
async function linkTechnician(tx: Tx, userId: string, body: Pick<UserBody, 'role' | 'technicianId' | 'email' | 'name' | 'phone' | 'active'>) {
  await tx.technician.updateMany({ where: { userId, ...(body.role === 'technician' ? { NOT: { id: body.technicianId ?? '' } } : {}) }, data: { userId: null } });
  if (body.role !== 'technician') return;
  let tech = body.technicianId ? await tx.technician.findUnique({ where: { id: body.technicianId } }) : await tx.technician.findFirst({ where: { email: { equals: body.email, mode: 'insensitive' } } });
  if (tech?.userId && tech.userId !== userId) throw validation({ technicianId: ['This technician profile is linked to another login.'] });
  tech ??= await tx.technician.create({ data: { name: body.name, email: body.email, phone: body.phone, specialty: 'General', active: body.active } });
  await tx.technician.update({ where: { id: tech.id }, data: { userId } });
}

async function validateUser(auth: AuthContext, body: UserBody, selfId?: string) {
  const errors: FieldErrors = {};
  if (await prisma.user.findFirst({ where: { email: body.email, ...(selfId ? { id: { not: selfId } } : {}) }, select: { id: true } })) errors.email = ['Another user already uses this email.'];
  if (body.role === 'super_admin' && auth.user.role !== 'super_admin') errors.role = ['Only a Super Admin can grant Super Admin.'];
  if (body.role === 'client' && !body.clinicId) errors.clinicId = ['Client users must be linked to a clinic.'];
  if (body.clinicId && !(await prisma.clinic.findUnique({ where: { id: body.clinicId }, select: { id: true } }))) errors.clinicId = ['Select a valid clinic.'];
  if (!selfId && !body.password) errors.password = ['Set an initial password of at least 8 characters.'];
  throwIfErrors(errors);
}

async function loadUser(id: string) {
  return toUser(await prisma.user.findUniqueOrThrow({ where: { id }, include: userInclude }));
}

export const userService = {
  async list(q: UserQuery, page: PageRequest): Promise<Paginated<User>> {
    const roleMatches = q.search ? (Object.entries(ROLE_LABELS) as [RoleKey, string][]).filter(([, label]) => label.toLowerCase().includes(q.search!.toLowerCase())).map(([k]) => k) : [];
    const rows = await prisma.user.findMany({
      where: {
        AND: [
          q.role ? { roleKey: q.role } : {},
          q.active === undefined ? {} : { active: q.active },
          q.search ? { OR: [{ name: insensitive(q.search) }, { email: insensitive(q.search) }, { phone: insensitive(q.search) }, { roleKey: { in: roleMatches } }] } : {},
        ],
      },
      include: userInclude,
    });
    const users = rows.map(toUser);
    const keys: Record<string, (u: User) => string | number | null | undefined> = {
      name: (u) => u.name,
      email: (u) => u.email,
      role: (u) => ROLE_LABELS[u.role],
      lastLoginAt: (u) => u.lastLoginAt,
      status: (u) => (u.active ? 1 : 0),
    };
    const sorted = sortBy(users, keys[q.sort ?? 'name'] ?? keys.name, q.dir);
    const { skip, take, meta } = paginate(page, sorted.length);
    return { data: sorted.slice(skip, skip + take), meta };
  },

  async create(auth: AuthContext, body: UserBody): Promise<User> {
    await validateUser(auth, body);
    const passwordHash = await hashPassword(body.password!);
    const id = await prisma.$transaction(async (tx) => {
      const u = await tx.user.create({
        data: { name: body.name, email: body.email, phone: body.phone, roleKey: body.role, active: body.active, clinicId: body.role === 'client' ? body.clinicId : null, passwordHash },
      });
      await linkTechnician(tx, u.id, body);
      await logActivity(tx, auth.user, { action: 'user.create', description: `Created user ${u.name} (${ROLE_LABELS[body.role]})`, subjectType: 'user', subjectId: u.id, subjectLabel: u.email });
      return u.id;
    });
    return loadUser(id);
  },

  async update(auth: AuthContext, id: string, body: UserBody): Promise<User> {
    const u = await prisma.user.findUnique({ where: { id } });
    if (!u) throw notFound();
    if (u.roleKey === 'super_admin' && auth.user.role !== 'super_admin') throw forbidden();
    await validateUser(auth, body, id);
    if (u.id === auth.user.id && (body.role !== u.roleKey || !body.active)) throw validation({ role: ['You cannot change your own role or disable yourself.'] });
    const passwordHash = body.password ? await hashPassword(body.password) : undefined;
    await prisma.$transaction(async (tx) => {
      await tx.user.update({
        where: { id },
        data: { name: body.name, email: body.email, phone: body.phone, roleKey: body.role, active: body.active, clinicId: body.role === 'client' ? body.clinicId : null, ...(passwordHash ? { passwordHash } : {}) },
      });
      // A new password or a disabled account ends that user's other sign-ins at once.
      if (passwordHash || !body.active) {
        await tx.session.updateMany({ where: { userId: id, revokedAt: null, ...(id === auth.user.id ? { NOT: { id: auth.sessionId } } : {}) }, data: { revokedAt: new Date() } });
      }
      await linkTechnician(tx, id, body);
      await logActivity(tx, auth.user, { action: 'user.update', description: `Updated user ${body.name}`, subjectType: 'user', subjectId: id, subjectLabel: body.email });
    });
    return loadUser(id);
  },

  async setActive(auth: AuthContext, id: string, active: boolean): Promise<User> {
    const u = await prisma.user.findUnique({ where: { id } });
    if (!u) throw notFound();
    if (u.id === auth.user.id) throw unprocessable('You cannot disable your own account.');
    if (u.roleKey === 'super_admin' && auth.user.role !== 'super_admin') throw forbidden();
    await prisma.$transaction(async (tx) => {
      await tx.user.update({ where: { id }, data: { active } });
      if (!active) await tx.session.updateMany({ where: { userId: id, revokedAt: null }, data: { revokedAt: new Date() } });
      await logActivity(tx, auth.user, { action: active ? 'user.enable' : 'user.disable', description: `${active ? 'Enabled' : 'Disabled'} ${u.name}`, subjectType: 'user', subjectId: id, subjectLabel: u.email });
    });
    return loadUser(id);
  },

  async remove(auth: AuthContext, id: string) {
    const u = await prisma.user.findUnique({
      where: { id },
      include: { _count: { select: { statusChanges: true, createdCases: true, notes: true, uploads: true, qualityChecks: true, deliveriesRecorded: true, paymentsReceived: true, assignmentsMade: true } } },
    });
    if (!u) throw notFound();
    if (u.id === auth.user.id) throw unprocessable('You cannot delete your own account.');
    if (u.roleKey === 'super_admin' && (await prisma.user.count({ where: { roleKey: 'super_admin' } })) <= 1) throw unprocessable('The last Super Admin cannot be deleted.');
    if (u.roleKey === 'super_admin' && auth.user.role !== 'super_admin') throw forbidden();
    if (Object.values(u._count).some((n) => n > 0)) throw unprocessable('This user has case history. Disable the account instead so the audit trail stays intact.');
    await prisma.$transaction(async (tx) => {
      await tx.user.delete({ where: { id } });
      await logActivity(tx, auth.user, { action: 'user.delete', description: `Deleted user ${u.name}`, subjectType: 'user', subjectLabel: u.email });
    });
  },
};

export const roleService = {
  async list(): Promise<Role[]> {
    const rows = await prisma.role.findMany({ include: { permissions: { select: { permissionKey: true } } } });
    const order: RoleKey[] = ['super_admin', 'admin', 'lab_manager', 'reception', 'technician', 'qc', 'delivery', 'client'];
    return rows
      .map((r) => ({ key: r.key as RoleKey, name: r.name, description: r.description, locked: r.locked, permissions: r.locked ? [...ALL_PERMISSION_KEYS] : r.permissions.map((p) => p.permissionKey) }))
      .sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key));
  },

  async permissions(): Promise<Permission[]> {
    const rows = await prisma.permission.findMany();
    const order = new Map(ALL_PERMISSION_KEYS.map((k, i) => [k, i]));
    return rows.map((p) => ({ key: p.key, label: p.label, group: p.group, description: p.description ?? undefined })).sort((a, b) => (order.get(a.key) ?? 999) - (order.get(b.key) ?? 999));
  },

  async update(auth: AuthContext, key: string, permissions: string[]): Promise<Role> {
    const role = await prisma.role.findUnique({ where: { key } });
    if (!role) throw notFound();
    if (role.locked) throw unprocessable(`${role.name} always has full access and cannot be edited.`);
    const known = new Set((await prisma.permission.findMany({ select: { key: true } })).map((p) => p.key));
    const requested = [...new Set(permissions.filter((p) => known.has(p)))];
    if (auth.user.role === role.key && !requested.includes(PERMISSIONS.ROLES_MANAGE)) throw unprocessable('You cannot remove your own access to roles.');
    await prisma.$transaction(async (tx) => {
      await tx.rolePermission.deleteMany({ where: { roleKey: key } });
      await tx.rolePermission.createMany({ data: requested.map((permissionKey) => ({ roleKey: key, permissionKey })) });
      await logActivity(tx, auth.user, { action: 'role.update', description: `Changed permissions of ${role.name}`, subjectType: 'role', subjectId: key, subjectLabel: role.name });
    });
    return (await this.list()).find((r) => r.key === key)!;
  },
};

export const catalogueService = {
  async list(includeInactive: boolean): Promise<LabService[]> {
    const rows = await prisma.labService.findMany({ where: includeInactive ? {} : { active: true }, orderBy: [{ caseType: 'asc' }, { name: 'asc' }] });
    return rows.map(toService);
  },

  async create(auth: AuthContext, body: ServiceBody): Promise<LabService> {
    const s = await prisma.$transaction(async (tx) => {
      const created = await tx.labService.create({ data: body });
      await logActivity(tx, auth.user, { action: 'service.create', description: `Added service ${created.name}`, subjectType: 'service', subjectId: created.id, subjectLabel: created.name });
      return created;
    });
    return toService(s);
  },

  /** Prices apply to new cases only — existing cases keep the unit price they were accepted at. */
  async update(auth: AuthContext, id: string, body: ServiceBody): Promise<LabService> {
    if (!(await prisma.labService.findUnique({ where: { id } }))) throw notFound();
    const s = await prisma.$transaction(async (tx) => {
      const updated = await tx.labService.update({ where: { id }, data: body });
      await logActivity(tx, auth.user, { action: 'service.update', description: `Updated service ${updated.name}`, subjectType: 'service', subjectId: id, subjectLabel: updated.name });
      return updated;
    });
    return toService(s);
  },

  async remove(auth: AuthContext, id: string) {
    const s = await prisma.labService.findUnique({ where: { id }, include: { _count: { select: { cases: true } } } });
    if (!s) throw notFound();
    if (s._count.cases) throw unprocessable('This service is used by existing cases. Deactivate it instead.');
    await prisma.$transaction(async (tx) => {
      await tx.labService.delete({ where: { id } });
      await logActivity(tx, auth.user, { action: 'service.delete', description: `Deleted service ${s.name}`, subjectType: 'service', subjectLabel: s.name });
    });
  },
};

export const settingsService = {
  get: () => readLabSettings(prisma),

  async update(auth: AuthContext, body: z.output<typeof settingsSchema>): Promise<LabSettings> {
    const current = await readLabSettings(prisma);
    return prisma.$transaction(async (tx) => {
      const saved = await writeLabSettings(tx, { ...current, ...body });
      await logActivity(tx, auth.user, { action: 'settings.update', description: 'Updated lab settings', subjectType: 'settings' });
      return saved;
    });
  },
};

/**
 * The audit trail: the activity log plus every case status change (from the
 * status history, which carries the note), newest first.
 */
export const activityService = {
  async list(q: { search?: string; subjectType?: string }, page: PageRequest): Promise<Paginated<ActivityLogEntry>> {
    const feed = Prisma.sql`
      SELECT a.id, a."userId", a."userName", a.action, a.description, a."subjectType", a."subjectId", a."subjectLabel", a."createdAt"
        FROM activity_log a
       WHERE NOT (a.action LIKE 'case.%' AND a.action NOT IN ('case.create', 'case.update', 'case.delete') AND a.action NOT LIKE 'case.file%')
      UNION ALL
      SELECT h.id, h."userId", u.name AS "userName", 'case.status.' || h."toStatus"::text AS action,
             CASE WHEN h."fromStatus" IS NULL THEN 'Created as ' || replace(h."toStatus"::text, '_', ' ')
                  ELSE 'Moved from ' || replace(h."fromStatus"::text, '_', ' ') || ' to ' || replace(h."toStatus"::text, '_', ' ') END
             || COALESCE(' — ' || h.note, '') AS description,
             'case' AS "subjectType", h."caseId" AS "subjectId", c."caseNumber" AS "subjectLabel", h."createdAt"
        FROM case_status_history h JOIN users u ON u.id = h."userId" JOIN cases c ON c.id = h."caseId"`;
    const conditions: Prisma.Sql[] = [];
    if (q.subjectType) conditions.push(Prisma.sql`"subjectType" = ${q.subjectType}`);
    if (q.search) {
      const like = `%${q.search.replace(/[\\%_]/g, (m) => `\\${m}`)}%`;
      conditions.push(Prisma.sql`("userName" ILIKE ${like} OR description ILIKE ${like} OR "subjectLabel" ILIKE ${like})`);
    }
    const where = conditions.length ? Prisma.sql`WHERE ${Prisma.join(conditions, ' AND ')}` : Prisma.empty;
    const [{ total }] = await prisma.$queryRaw<{ total: bigint }[]>`SELECT COUNT(*)::bigint AS total FROM (${feed}) f ${where}`;
    const { skip, take, meta } = paginate(page, Number(total));
    const rows = await prisma.$queryRaw<Prisma.ActivityLogGetPayload<object>[]>`SELECT * FROM (${feed}) f ${where} ORDER BY "createdAt" DESC, id DESC LIMIT ${take} OFFSET ${skip}`;
    return { data: rows.map(toActivity), meta };
  },
};
