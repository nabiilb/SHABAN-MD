import type { Actor } from '@48hrs/shared/workflow';
import type { User } from '@48hrs/shared/types';

/** Who is calling: resolved by the authenticate middleware from the access-token cookie. */
export interface AuthContext {
  user: User;
  permissions: string[];
  sessionId: string;
  sessionExpiresAt: Date;
}

export function actorOf(auth: AuthContext): Actor {
  return { user: auth.user, permissions: auth.permissions };
}
