import { afterEach, describe, expect, it, vi } from 'vitest';
import { buildQuery, httpTransport } from '@/services/api/http-transport';
import { ApiError } from '@/services/api/errors';

afterEach(() => vi.unstubAllGlobals());

describe('HTTP transport (Node API mode)', () => {
  it('serialises filters as repeated keys and drops empty values', () => {
    expect(buildQuery({ status: ['received', 'assigned'], page: 2, search: '', clinicId: undefined, openOnly: true })).toBe('?status=received&status=assigned&page=2&openOnly=true');
  });

  it('sends JSON with cookies and the CSRF header, never a bearer token', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ ok: 1 }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchMock);
    const res = await httpTransport({ method: 'POST', path: '/cases', body: { a: 1 }, token: 'ignored' });
    expect(res).toEqual({ ok: 1 });
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = init.headers as Record<string, string>;
    expect(init.credentials).toBe('include');
    expect(headers['X-Requested-With']).toBe('XMLHttpRequest');
    expect(headers.Authorization).toBeUndefined();
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
});
