import { CASE_TYPE_LABELS } from '@48hrs/shared/constants';
import { ALL_PERMISSION_KEYS, PERMISSION_CATALOGUE, PERMISSIONS, ROLE_LABELS } from '@48hrs/shared/permissions';
import { ApiError } from '@/services/api/errors';
import { toIso } from '@48hrs/shared/dates';
import type { ServicePayload, UserPayload } from '@48hrs/shared/types';
import type { ActivityLogEntry, LabService, LabSettings, RoleKey } from '@48hrs/shared/types';
import { authenticate, authorize, publicUser } from '../auth-context';
import { nextId, resetDb, type MockUser } from '../db';
import { logActivity } from '../domain';
import { hashPassword, randomHex } from '../sha256';
import { EMAIL_RE, forbidden, includesText, notFound, paginate, qBool, qStr, requireFields, route, sortItems, validationError, type FieldErrors } from '../router';


/* --------------------------------- Users -------------------------------- */

route('GET', '/users', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.USERS_VIEW);
  const q = raw.query;
  const active = qBool(q, 'active');
  const items = ctx.db.users
    .filter((u) => !qStr(q, 'role') || u.role === qStr(q, 'role'))
    .filter((u) => active === undefined || u.active === active)
    .filter((u) => includesText([u.name, u.email, u.phone, ROLE_LABELS[u.role]], qStr(q, 'search')))
    .map(publicUser);
  return paginate(sortItems(items, q, { name: (u) => u.name, email: (u) => u.email, role: (u) => ROLE_LABELS[u.role], lastLoginAt: (u) => u.lastLoginAt ?? null, status: (u) => (u.active ? 1 : 0) }, 'name'), q);
});

function validateUser(ctx: ReturnType<typeof authenticate>, body: Partial<UserPayload>, selfId?: string) {
  requireFields(body as Record<string, unknown>, { name: 'Full name', email: 'Email', role: 'Role' });
  const errors: FieldErrors = {};
  const email = String(body.email).trim().toLowerCase();
  if (!EMAIL_RE.test(email)) errors.email = ['Enter a valid email address.'];
  else if (ctx.db.users.some((u) => u.id !== selfId && u.email.toLowerCase() === email)) errors.email = ['Another user already uses this email.'];
  if (!(String(body.role) in ROLE_LABELS)) errors.role = ['Choose a role.'];
  if (body.role === 'super_admin' && ctx.user.role !== 'super_admin') errors.role = ['Only a Super Admin can grant Super Admin.'];
  if (body.role === 'client' && !body.clinicId) errors.clinicId = ['Client users must be linked to a clinic.'];
  if (body.clinicId && !ctx.db.clinics.some((k) => k.id === body.clinicId)) errors.clinicId = ['Select a valid clinic.'];
  if (!selfId && (!body.password || body.password.length < 8)) errors.password = ['Set an initial password of at least 8 characters.'];
  if (selfId && body.password && body.password.length < 8) errors.password = ['Use at least 8 characters.'];
  if (Object.keys(errors).length) throw validationError(errors);
}

function linkTechnician(ctx: ReturnType<typeof authenticate>, u: MockUser, requested?: string | null) {
  if (u.role !== 'technician') {
    u.technicianId = null;
    return;
  }
  let tech = requested ? ctx.db.technicians.find((t) => t.id === requested) : ctx.db.technicians.find((t) => t.email.toLowerCase() === u.email.toLowerCase());
  if (!tech) {
    tech = { id: nextId('tec'), userId: u.id, name: u.name, email: u.email, phone: u.phone ?? '', specialty: 'General', active: u.active, createdAt: toIso(ctx.now) };
    ctx.db.technicians.push(tech);
  }
  tech.userId = u.id;
  u.technicianId = tech.id;
}

route('POST', '/users', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.USERS_MANAGE);
  const body = (raw.body ?? {}) as UserPayload;
  validateUser(ctx, body);
  const salt = randomHex(8);
  const u: MockUser = {
    id: nextId('usr'),
    name: body.name.trim(),
    email: body.email.trim().toLowerCase(),
    phone: body.phone?.trim() ?? '',
    role: body.role,
    active: body.active !== false,
    clinicId: body.role === 'client' ? body.clinicId ?? null : null,
    doctorId: null,
    technicianId: null,
    lastLoginAt: null,
    createdAt: toIso(ctx.now),
    passwordSalt: salt,
    passwordHash: hashPassword(body.password!, salt),
  };
  ctx.db.users.push(u);
  linkTechnician(ctx, u, body.technicianId);
  logActivity(ctx.db, ctx.user, { action: 'user.create', description: `Created user ${u.name} (${ROLE_LABELS[u.role]})`, subjectType: 'user', subjectId: u.id, subjectLabel: u.email }, ctx.now);
  return publicUser(u);
});

route('PUT', '/users/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.USERS_MANAGE);
  const u = ctx.db.users.find((x) => x.id === raw.params.id);
  if (!u) throw notFound();
  const body = (raw.body ?? {}) as UserPayload;
  validateUser(ctx, body, u.id);
  if (u.role === 'super_admin' && ctx.user.role !== 'super_admin') throw forbidden();
  if (u.id === ctx.user.id && (body.role !== u.role || body.active === false)) throw validationError({ role: ['You cannot change your own role or disable yourself.'] });
  Object.assign(u, {
    name: body.name.trim(),
    email: body.email.trim().toLowerCase(),
    phone: body.phone?.trim() ?? '',
    role: body.role,
    active: body.active !== false,
    clinicId: body.role === 'client' ? body.clinicId ?? null : null,
  });
  if (body.password) {
    u.passwordSalt = randomHex(8);
    u.passwordHash = hashPassword(body.password, u.passwordSalt);
    ctx.db.sessions = ctx.db.sessions.filter((s) => s.userId !== u.id || s.token === ctx.token);
  }
  linkTechnician(ctx, u, body.technicianId);
  if (!u.active) ctx.db.sessions = ctx.db.sessions.filter((s) => s.userId !== u.id);
  logActivity(ctx.db, ctx.user, { action: 'user.update', description: `Updated user ${u.name}`, subjectType: 'user', subjectId: u.id, subjectLabel: u.email }, ctx.now);
  return publicUser(u);
});

route('PATCH', '/users/:id/status', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.USERS_MANAGE);
  const u = ctx.db.users.find((x) => x.id === raw.params.id);
  if (!u) throw notFound();
  if (u.id === ctx.user.id) throw new ApiError(422, 'You cannot disable your own account.');
  if (u.role === 'super_admin' && ctx.user.role !== 'super_admin') throw forbidden();
  u.active = Boolean((raw.body as { active?: boolean })?.active);
  if (!u.active) ctx.db.sessions = ctx.db.sessions.filter((s) => s.userId !== u.id);
  logActivity(ctx.db, ctx.user, { action: u.active ? 'user.enable' : 'user.disable', description: `${u.active ? 'Enabled' : 'Disabled'} ${u.name}`, subjectType: 'user', subjectId: u.id, subjectLabel: u.email }, ctx.now);
  return publicUser(u);
});

route('DELETE', '/users/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.USERS_MANAGE);
  const u = ctx.db.users.find((x) => x.id === raw.params.id);
  if (!u) throw notFound();
  if (u.id === ctx.user.id) throw new ApiError(422, 'You cannot delete your own account.');
  if (u.role === 'super_admin' && ctx.db.users.filter((x) => x.role === 'super_admin').length <= 1) throw new ApiError(422, 'The last Super Admin cannot be deleted.');
  if (ctx.db.history.some((h) => h.userId === u.id)) throw new ApiError(422, 'This user has case history. Disable the account instead so the audit trail stays intact.');
  ctx.db.users = ctx.db.users.filter((x) => x !== u);
  ctx.db.sessions = ctx.db.sessions.filter((s) => s.userId !== u.id);
  logActivity(ctx.db, ctx.user, { action: 'user.delete', description: `Deleted user ${u.name}`, subjectType: 'user', subjectLabel: u.email }, ctx.now);
  return null;
});

/* ------------------------- Roles & permissions -------------------------- */

route('GET', '/roles', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, [PERMISSIONS.USERS_VIEW, PERMISSIONS.ROLES_MANAGE], 'any');
  return ctx.db.roles;
});

route('GET', '/permissions', (raw) => {
  authenticate(raw);
  return PERMISSION_CATALOGUE;
});

route('PUT', '/roles/:key', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.ROLES_MANAGE);
  const role = ctx.db.roles.find((r) => r.key === (raw.params.key as RoleKey));
  if (!role) throw notFound();
  if (role.locked) throw new ApiError(422, `${role.name} always has full access and cannot be edited.`);
  const requested = ((raw.body as { permissions?: string[] })?.permissions ?? []).filter((p) => ALL_PERMISSION_KEYS.includes(p));
  if (ctx.user.role === role.key && !requested.includes(PERMISSIONS.ROLES_MANAGE)) throw new ApiError(422, 'You cannot remove your own access to roles.');
  role.permissions = [...new Set(requested)];
  logActivity(ctx.db, ctx.user, { action: 'role.update', description: `Changed permissions of ${role.name}`, subjectType: 'role', subjectId: role.key, subjectLabel: role.name }, ctx.now);
  return role;
});

/* ------------------------------- Services ------------------------------- */

route('GET', '/services', (raw) => {
  const ctx = authenticate(raw);
  const all = qBool(raw.query, 'includeInactive');
  return ctx.db.services.filter((s) => all || s.active);
});

function validateService(body: Partial<ServicePayload>) {
  requireFields(body as Record<string, unknown>, { name: 'Service name', caseType: 'Case type', unitMode: 'Billing unit', defaultMaterial: 'Default material' });
  const errors: FieldErrors = {};
  const price = Number(body.unitPrice);
  if (!Number.isFinite(price) || price < 0) errors.unitPrice = ['Enter a price of 0 or more.'];
  if (!(String(body.caseType) in CASE_TYPE_LABELS)) errors.caseType = ['Choose a case type.'];
  if (!['tooth', 'denture', 'arch'].includes(String(body.unitMode))) errors.unitMode = ['Choose a billing unit.'];
  if (Object.keys(errors).length) throw validationError(errors);
}

route('POST', '/services', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.SERVICES_MANAGE);
  const body = (raw.body ?? {}) as ServicePayload;
  validateService(body);
  const s: LabService = { id: nextId('svc'), name: body.name.trim(), caseType: body.caseType, unitMode: body.unitMode, unitPrice: Number(body.unitPrice), defaultMaterial: body.defaultMaterial.trim(), active: body.active !== false };
  ctx.db.services.push(s);
  logActivity(ctx.db, ctx.user, { action: 'service.create', description: `Added service ${s.name}`, subjectType: 'service', subjectId: s.id, subjectLabel: s.name }, ctx.now);
  return s;
});

route('PUT', '/services/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.SERVICES_MANAGE);
  const s = ctx.db.services.find((x) => x.id === raw.params.id);
  if (!s) throw notFound();
  const body = (raw.body ?? {}) as ServicePayload;
  validateService(body);
  // Prices apply to new cases only — existing cases keep their unitPrice.
  Object.assign(s, { name: body.name.trim(), caseType: body.caseType, unitMode: body.unitMode, unitPrice: Number(body.unitPrice), defaultMaterial: body.defaultMaterial.trim(), active: body.active !== false });
  logActivity(ctx.db, ctx.user, { action: 'service.update', description: `Updated service ${s.name}`, subjectType: 'service', subjectId: s.id, subjectLabel: s.name }, ctx.now);
  return s;
});

route('DELETE', '/services/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.SERVICES_MANAGE);
  const s = ctx.db.services.find((x) => x.id === raw.params.id);
  if (!s) throw notFound();
  if (ctx.db.cases.some((c) => c.serviceId === s.id)) throw new ApiError(422, 'This service is used by existing cases. Deactivate it instead.');
  ctx.db.services = ctx.db.services.filter((x) => x !== s);
  logActivity(ctx.db, ctx.user, { action: 'service.delete', description: `Deleted service ${s.name}`, subjectType: 'service', subjectLabel: s.name }, ctx.now);
  return null;
});

/* ------------------------------- Settings ------------------------------- */

route('GET', '/settings', (raw) => {
  const ctx = authenticate(raw);
  return ctx.db.settings;
});

route('PUT', '/settings', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.SETTINGS_MANAGE);
  const body = (raw.body ?? {}) as LabSettings;
  requireFields(body as unknown as Record<string, unknown>, { labName: 'Lab name', currency: 'Currency' });
  const errors: FieldErrors = {};
  const int = (k: keyof LabSettings, min: number, max: number, label: string) => {
    const v = Number(body[k]);
    if (!Number.isFinite(v) || v < min || v > max) errors[k] = [`${label} must be between ${min} and ${max}.`];
  };
  int('slaHours', 4, 240, 'Turnaround');
  int('atRiskHours', 1, 72, 'At-risk threshold');
  int('criticalHours', 1, 48, 'Critical threshold');
  int('emergencyFeePerUnit', 0, 1000, 'Emergency fee');
  int('invoiceDueDays', 0, 120, 'Invoice terms');
  if (Number(body.criticalHours) >= Number(body.atRiskHours)) errors.criticalHours = ['Critical must be lower than the at-risk threshold.'];
  if (!/^[A-Z]{3}$/.test(String(body.currency))) errors.currency = ['Use a 3-letter ISO currency code, e.g. USD.'];
  if (Object.keys(errors).length) throw validationError(errors);
  ctx.db.settings = {
    ...ctx.db.settings,
    ...body,
    slaHours: Number(body.slaHours),
    atRiskHours: Number(body.atRiskHours),
    criticalHours: Number(body.criticalHours),
    emergencyFeePerUnit: Number(body.emergencyFeePerUnit),
    invoiceDueDays: Number(body.invoiceDueDays),
  };
  logActivity(ctx.db, ctx.user, { action: 'settings.update', description: 'Updated lab settings', subjectType: 'settings' }, ctx.now);
  return ctx.db.settings;
});

route('POST', '/settings/reset-demo', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.SETTINGS_MANAGE);
  resetDb(ctx.now);
  return null;
});

/* ------------------------------ Activity log ---------------------------- */

route('GET', '/activity', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.AUDIT_VIEW);
  const q = raw.query;
  const { db } = ctx;
  // Case status changes live in the history table; merge them into one feed.
  const fromHistory: ActivityLogEntry[] = db.history.map((h) => {
    const c = db.cases.find((x) => x.id === h.caseId);
    return {
      id: h.id,
      userId: h.userId,
      userName: h.userName,
      action: `case.status.${h.toStatus}`,
      description: `${h.fromStatus ? `Moved from ${h.fromStatus.replace(/_/g, ' ')} to` : 'Created as'} ${h.toStatus.replace(/_/g, ' ')}${h.note ? ` — ${h.note}` : ''}`,
      subjectType: 'case',
      subjectId: h.caseId,
      subjectLabel: c?.caseNumber ?? null,
      createdAt: h.createdAt,
    };
  });
  const own = db.activity.filter((a) => !a.action.startsWith('case.') || a.action === 'case.create' || a.action === 'case.update' || a.action === 'case.delete' || a.action.startsWith('case.file'));
  const items = [...fromHistory, ...own]
    .filter((a) => !qStr(q, 'subjectType') || a.subjectType === qStr(q, 'subjectType'))
    .filter((a) => includesText([a.userName, a.description, a.subjectLabel], qStr(q, 'search')))
    .sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  return paginate(items, q);
});
