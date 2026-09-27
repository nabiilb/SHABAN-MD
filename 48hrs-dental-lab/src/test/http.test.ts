import { afterEach, describe, expect, it, vi } from 'vitest';
import { buildQuery, httpTransport } from '@/services/api/http-transport';
import { ApiError } from '@/services/api/errors';
import { camelKeys, snakeKeys } from '@/services/api/case-transform';

afterEach(() => vi.unstubAllGlobals());

describe('HTTP transport (real backend mode)', () => {
  it('serialises filters the way Laravel expects', () => {
    expect(buildQuery({ status: ['received', 'assigned'], page: 2, search: '', clinicId: undefined, openOnly: true })).toBe('?status%5B%5D=received&status%5B%5D=assigned&page=2&openOnly=true');
  });

  it('sends JSON with the bearer token and parses the response', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ ok: 1 }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchMock);
    const res = await httpTransport({ method: 'POST', path: '/cases', body: { a: 1 }, token: 'tok' });
    expect(res).toEqual({ ok: 1 });
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer tok');
    expect(init.body).toBe('{"a":1}');
  });

  it('turns a 422 into an ApiError with field errors', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Invalid', errors: { email: ['Taken'] } }), { status: 422 })));
    const err = await httpTransport({ method: 'POST', path: '/users', body: {} }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).fieldErrors.email).toEqual(['Taken']);
  });

  it('reports network failures clearly', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));
    const err = (await httpTransport({ method: 'GET', path: '/cases' }).catch((e: unknown) => e)) as ApiError;
    expect(err.status).toBe(0);
    expect(err.message).toMatch(/Cannot reach the server/);
  });

  it('converts keys between camelCase and snake_case', () => {
    expect(snakeKeys({ caseNumber: 'x', items: [{ dueAt: 1 }] })).toEqual({ case_number: 'x', items: [{ due_at: 1 }] });
    expect(camelKeys({ received_at: 1, due_at_2: 2 })).toEqual({ receivedAt: 1, dueAt2: 2 });
  });
});
