import { ApiError } from '@/services/api/errors';
import { hasPermission } from '@/lib/permissions';
import type { Actor } from '@/lib/workflow';
import type { MockDatabase, MockUser } from './db';
import type { AuthedContext, MockContext } from './router';

export function permissionsFor(db: MockDatabase, user: MockUser) {
  return db.roles.find((r) => r.key === user.role)?.permissions ?? [];
}

/** Resolves the bearer token like auth middleware would (401 when missing/expired). */
export function authenticate(ctx: MockContext): AuthedContext {
  if (!ctx.token) throw new ApiError(401, 'Please sign in to continue.');
  const session = ctx.db.sessions.find((s) => s.token === ctx.token);
  if (!session || new Date(session.expiresAt).getTime() <= ctx.now) {
    if (session) ctx.db.sessions = ctx.db.sessions.filter((s) => s !== session);
    throw new ApiError(401, 'Your session has expired. Please sign in again.');
  }
  const user = ctx.db.users.find((u) => u.id === session.userId);
  if (!user || !user.active) throw new ApiError(401, 'This account is no longer active.');
  return { ...ctx, user, permissions: permissionsFor(ctx.db, user), session };
}

/** Server-side permission gate (like Laravel's `authorize()` / `can:` middleware). */
export function authorize(ctx: AuthedContext, permission: string | string[], mode: 'all' | 'any' = 'all') {
  if (!hasPermission(ctx.permissions, permission, mode)) {
    throw new ApiError(403, 'You do not have permission to do that.');
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
