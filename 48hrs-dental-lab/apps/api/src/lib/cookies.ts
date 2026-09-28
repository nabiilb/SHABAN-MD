import type { CookieOptions, Response } from 'express';
import { env } from '../config/env.ts';

export const ACCESS_COOKIE = 'lab_access';
export const REFRESH_COOKIE = 'lab_refresh';

/** Access tokens go to every API route; refresh tokens only to /api/auth. */
const base = (path: string): CookieOptions => ({
  httpOnly: true,
  secure: env.COOKIE_SECURE,
  sameSite: env.COOKIE_SAMESITE,
  domain: env.COOKIE_DOMAIN,
  path,
});

export function setSessionCookies(res: Response, tokens: { access: string; refresh: string }, sessionExpiresAt: Date, now = Date.now()) {
  res.cookie(ACCESS_COOKIE, tokens.access, { ...base('/api'), maxAge: env.ACCESS_TOKEN_TTL_MINUTES * 60_000 });
  res.cookie(REFRESH_COOKIE, tokens.refresh, { ...base('/api/auth'), maxAge: Math.max(0, sessionExpiresAt.getTime() - now) });
}

export function clearSessionCookies(res: Response) {
  res.clearCookie(ACCESS_COOKIE, base('/api'));
  res.clearCookie(REFRESH_COOKIE, base('/api/auth'));
}
