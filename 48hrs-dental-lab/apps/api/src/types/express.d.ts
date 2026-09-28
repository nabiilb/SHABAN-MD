import type { AuthContext } from './auth.ts';

declare global {
  namespace Express {
    interface Request {
      /** Set by the authenticate middleware on protected routes. */
      auth?: AuthContext;
    }
  }
}

export {};
