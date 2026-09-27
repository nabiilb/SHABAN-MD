/**
 * Keeps the bearer token for API calls. With Laravel Sanctum SPA (cookie) auth,
 * set VITE_API_WITH_CREDENTIALS=true — the token is then only used for mocks.
 * localStorage keeps the session across reloads; the expiry is enforced client-side
 * and the server must also reject expired tokens (401 → automatic logout).
 */
const KEY = '48hrs.session';

export interface StoredSession {
  token: string;
  expiresAt: string;
}

export const tokenStore = {
  get(): StoredSession | null {
    try {
      const raw = localStorage.getItem(KEY);
      if (!raw) return null;
      const s = JSON.parse(raw) as StoredSession;
      if (!s.token || !s.expiresAt) return null;
      if (new Date(s.expiresAt).getTime() <= Date.now()) {
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
