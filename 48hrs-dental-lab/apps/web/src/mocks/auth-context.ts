import { API_ERRORS } from '@48hrs/shared/errors';
import { ApiError } from '@/services/api/errors';
import { hasPermission } from '@48hrs/shared/permissions';
import type { Actor } from '@48hrs/shared/workflow';
import type { MockDatabase, MockUser } from './db';
import { forbidden, type AuthedContext, type MockContext } from './router';

export function permissionsFor(db: MockDatabase, user: MockUser) {
  return db.roles.find((r) => r.key === user.role)?.permissions ?? [];
}

/** Resolves the bearer token like auth middleware would (401 when missing/expired). */
export function authenticate(ctx: MockContext): AuthedContext {
  if (!ctx.token) throw new ApiError(401, API_ERRORS.unauthenticated);
  const session = ctx.db.sessions.find((s) => s.token === ctx.token);
  if (!session || new Date(session.expiresAt).getTime() <= ctx.now) {
    if (session) ctx.db.sessions = ctx.db.sessions.filter((s) => s !== session);
    throw new ApiError(401, API_ERRORS.sessionExpired);
  }
  const user = ctx.db.users.find((u) => u.id === session.userId);
  if (!user || !user.active) throw new ApiError(401, 'This account is no longer active.');
  return { ...ctx, user, permissions: permissionsFor(ctx.db, user), session };
}

/** Server-side permission gate (mirrors the API's authorize() middleware). */
export function authorize(ctx: AuthedContext, permission: string | string[], mode: 'all' | 'any' = 'all') {
  if (!hasPermission(ctx.permissions, permission, mode)) {
    throw forbidden();
  }
}

export function actorOf(ctx: AuthedContext): Actor {
  return { user: ctx.user, permissions: ctx.permissions };
}

export function publicUser(u: MockUser) {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const { passwordHash, passwordSalt, ...rest } = u;
  return rest;
}
