import { create } from 'zustand';
import { authService } from '@/services/authService';
import { ApiError } from '@/services/api/errors';
import { setSessionRefreshedHandler, setUnauthorizedHandler } from '@/services/api/client';
import { serverClock } from '@/services/api/server-clock';
import { tokenStore } from '@/services/api/token-store';
import type { AuthSession, User } from '@48hrs/shared/types';
import type { LoginPayload } from '@48hrs/shared/types';

export type AuthStatus = 'booting' | 'authenticated' | 'anonymous';
export type LogoutReason = 'manual' | 'expired';

interface AuthState {
  status: AuthStatus;
  user: User | null;
  permissions: string[];
  expiresAt: string | null;
  /** Why the last session ended — shown on the login page. */
  endedReason: LogoutReason | null;
  bootstrap: () => Promise<void>;
  login: (payload: LoginPayload) => Promise<User>;
  logout: (reason?: LogoutReason) => Promise<void>;
  refresh: () => Promise<void>;
}

let expiryTimer: ReturnType<typeof setTimeout> | undefined;

function scheduleExpiry(expiresAt: string, onExpire: () => void) {
  clearTimeout(expiryTimer);
  // setTimeout overflows above ~24.8 days; sessions are much shorter, but clamp anyway.
  const ms = Math.min(new Date(expiresAt).getTime() - serverClock.now(), 2_000_000_000);
  expiryTimer = setTimeout(onExpire, Math.max(0, ms));
}

export const useAuthStore = create<AuthState>((set, get) => {
  const apply = (s: AuthSession) => {
    tokenStore.set({ token: s.token, expiresAt: s.expiresAt });
    scheduleExpiry(s.expiresAt, () => void get().logout('expired'));
    set({ status: 'authenticated', user: s.user, permissions: s.permissions, expiresAt: s.expiresAt, endedReason: null });
  };

  const clear = (reason: LogoutReason | null) => {
    clearTimeout(expiryTimer);
    tokenStore.clear();
    set({ status: 'anonymous', user: null, permissions: [], expiresAt: null, endedReason: reason });
  };

  return {
    status: 'booting',
    user: null,
    permissions: [],
    expiresAt: null,
    endedReason: null,

    async bootstrap() {
      setUnauthorizedHandler(() => {
        if (get().status === 'authenticated') clear('expired');
      });
      setSessionRefreshedHandler((s) => {
        if (get().status === 'authenticated') apply(s);
      });
      if (!tokenStore.get()) {
        clear(null);
        return;
      }
      try {
        apply(await authService.me());
      } catch (err) {
        clear(err instanceof ApiError && err.isUnauthorized ? 'expired' : null);
      }
    },

    async login(payload) {
      const session = await authService.login(payload);
      apply(session);
      return session.user;
    },

    async logout(reason = 'manual') {
      if (reason === 'manual' && tokenStore.get()) {
        try {
          await authService.logout();
        } catch {
          /* the token is dropped locally either way */
        }
      }
      clear(reason);
    },

    /** Picks up permission/role changes made by an admin while the user is signed in. */
    async refresh() {
      if (get().status !== 'authenticated') return;
      try {
        apply(await authService.me());
      } catch {
        /* 401s are handled by the unauthorized handler */
      }
    },
  };
});
