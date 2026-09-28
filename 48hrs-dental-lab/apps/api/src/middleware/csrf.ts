import type { RequestHandler } from 'express';
import { env } from '../config/env.ts';
import { forbidden } from '../lib/errors.ts';

const SAFE = new Set(['GET', 'HEAD', 'OPTIONS']);

/**
 * CSRF defence for cookie authentication, on top of SameSite cookies:
 *  - state-changing requests must carry X-Requested-With (a custom header a
 *    cross-site form cannot send, and cross-origin fetch cannot send without
 *    passing CORS);
 *  - when the browser sends an Origin, it must be an allowed one.
 */
export const csrfGuard: RequestHandler = (req, _res, next) => {
  if (SAFE.has(req.method)) return next();
  if (!req.get('x-requested-with')) return next(forbidden());
  const origin = req.get('origin');
  if (origin && !isAllowedOrigin(origin, req.get('host'))) return next(forbidden());
  next();
};

export function isAllowedOrigin(origin: string, host?: string) {
  if (env.CORS_ORIGINS.includes(origin)) return true;
  // Same-origin deployments (SPA and API behind one host) need no CORS entry.
  try {
    return !!host && new URL(origin).host === host;
  } catch {
    return false;
  }
}
