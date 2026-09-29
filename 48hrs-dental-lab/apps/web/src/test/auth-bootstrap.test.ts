import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/services/api/errors';
import { tokenStore } from '@/services/api/token-store';
import { authService } from '@/services/authService';
import { useAuthStore } from '@/stores/auth-store';

const future = () => new Date(Date.now() + 3_600_000).toISOString();

afterEach(() => vi.restoreAllMocks());

describe('restoring the session on load', () => {
  it('a 401 from /auth/me ends the stored session ("expired")', async () => {
    tokenStore.set({ expiresAt: future() });
    vi.spyOn(authService, 'me').mockRejectedValue(new ApiError(401, ''));
    await useAuthStore.getState().bootstrap();
    expect(useAuthStore.getState()).toMatchObject({ status: 'anonymous', endedReason: 'expired' });
    expect(tokenStore.get()).toBeNull();
  });

  it('a network error or an aborted request keeps the stored session for the next load', async () => {
    tokenStore.set({ expiresAt: future() });
    vi.spyOn(authService, 'me').mockRejectedValue(new ApiError(0, ''));
    await useAuthStore.getState().bootstrap();
    expect(useAuthStore.getState().status).toBe('anonymous');
    expect(tokenStore.get()).not.toBeNull();

    vi.spyOn(authService, 'me').mockRejectedValue(new DOMException('aborted', 'AbortError'));
    await useAuthStore.getState().bootstrap();
    expect(tokenStore.get()).not.toBeNull();
  });
});
