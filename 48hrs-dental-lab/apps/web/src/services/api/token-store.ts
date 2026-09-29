/**
 * Remembers that a session exists and when it ends, so the app knows whether
 * to restore it on load and when to sign out. With the Laravel API the session
 * itself lives in an HTTP-only cookie and are never visible here; only the
 * mock backend (VITE_USE_MOCKS=true) passes a bearer token through this store.
 */
import { serverClock } from './server-clock';

const KEY = '48hrs.session';

export interface StoredSession {
  /** Mock backend only. */
  token?: string;
  expiresAt: string;
}

export const tokenStore = {
  get(): StoredSession | null {
    try {
      const raw = localStorage.getItem(KEY);
      if (!raw) return null;
      const s = JSON.parse(raw) as StoredSession;
      if (!s.expiresAt) return null;
      if (new Date(s.expiresAt).getTime() <= serverClock.now()) {
        localStorage.removeItem(KEY);
        return null;
      }
      return s;
    } catch {
      return null;
    }
  },
  set(s: StoredSession) {
    try {
      localStorage.setItem(KEY, JSON.stringify(s));
    } catch {
      /* storage unavailable — session lives in memory only */
    }
  },
  clear() {
    try {
      localStorage.removeItem(KEY);
    } catch {
      /* ignore */
    }
  },
};
