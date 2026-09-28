import type { NextFunction, Request, RequestHandler, Response } from 'express';
import { API_ERRORS } from '@48hrs/shared/errors';
import { hasPermission } from '@48hrs/shared/permissions';
import { ACCESS_COOKIE } from '../lib/cookies.ts';
import { forbidden, unauthorized } from '../lib/errors.ts';
import { verifyAccessToken } from '../lib/tokens.ts';
import { authService } from '../services/auth-service.ts';
import type { AuthContext } from '../types/auth.ts';

/**
 * Requires a valid access-token cookie belonging to a live session. A 401 tells
 * the client to call /auth/refresh once; if that fails too, the user signs in again.
 */
export const authenticate: RequestHandler = async (req, _res, next) => {
  const token = (req.cookies as Record<string, string | undefined>)[ACCESS_COOKIE];
  if (!token) return next(unauthorized());
  const verified = await verifyAccessToken(token);
  if (!verified.ok) return next(unauthorized(verified.expired ? API_ERRORS.sessionExpired : API_ERRORS.unauthenticated));
  const auth = await authService.resolve(verified.claims.sub, verified.claims.sid);
  if (!auth) return next(unauthorized(API_ERRORS.sessionExpired));
  req.auth = auth;
  next();
};

/** The authenticated caller (only valid behind `authenticate`). */
export function authOf(req: Request): AuthContext {
  if (!req.auth) throw unauthorized();
  return req.auth;
}

/** Route guard: the caller must hold the permission(s). Enforced server-side regardless of the UI. */
export function authorize(permission: string | string[], mode: 'all' | 'any' = 'all'): RequestHandler {
  return (req: Request, _res: Response, next: NextFunction) => {
    const auth = req.auth;
    if (!auth) return next(unauthorized());
    if (!hasPermission(auth.permissions, permission, mode)) return next(forbidden());
    next();
  };
}
