import type { Request, Response } from 'express';
import { forgotPasswordSchema, loginSchema, resetPasswordSchema } from '@48hrs/shared/schemas';
import { clearSessionCookies, REFRESH_COOKIE, setSessionCookies } from '../lib/cookies.ts';
import { HttpError } from '../lib/errors.ts';
import { verifyAccessToken } from '../lib/tokens.ts';
import { ACCESS_COOKIE } from '../lib/cookies.ts';
import { authOf } from '../middleware/auth.ts';
import { parseBody } from '../middleware/validate.ts';
import { authService } from '../services/auth-service.ts';

const cookie = (req: Request, name: string) => (req.cookies as Record<string, string | undefined>)[name];

export const authController = {
  async login(req: Request, res: Response) {
    const { email, password } = parseBody(loginSchema, req.body);
    const issued = await authService.login(email, password, { ip: req.ip, userAgent: req.get('user-agent') });
    setSessionCookies(res, issued.tokens, issued.expiresAt);
    res.json(issued.session);
  },

  async refresh(req: Request, res: Response) {
    try {
      const issued = await authService.refresh(cookie(req, REFRESH_COOKIE));
      setSessionCookies(res, issued.tokens, issued.expiresAt);
      res.json(issued.session);
    } catch (err) {
      if (err instanceof HttpError && err.status === 401) clearSessionCookies(res);
      throw err;
    }
  },

  /** Works with an expired access token too: whatever identifies the session is revoked. */
  async logout(req: Request, res: Response) {
    const access = cookie(req, ACCESS_COOKIE);
    const verified = access ? await verifyAccessToken(access) : null;
    await authService.logout(verified?.ok ? verified.claims.sid : undefined, cookie(req, REFRESH_COOKIE));
    clearSessionCookies(res);
    res.status(204).end();
  },

  async me(req: Request, res: Response) {
    res.json(await authService.me(authOf(req)));
  },

  async forgotPassword(req: Request, res: Response) {
    const { email } = parseBody(forgotPasswordSchema, req.body);
    res.json(await authService.forgotPassword(email));
  },

  async resetPassword(req: Request, res: Response) {
    const body = parseBody(resetPasswordSchema, req.body);
    res.json(await authService.resetPassword(body));
  },
};
