import { afterEach, describe, expect, it, vi } from 'vitest';
import { buildQuery, httpTransport } from '@/services/api/http-transport';
import { ApiError } from '@/services/api/errors';

function clearCookies() {
  for (const c of document.cookie.split(';')) {
    const name = c.split('=')[0].trim();
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  }
}

afterEach(() => {
  vi.unstubAllGlobals();
  clearCookies();
});

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('HTTP transport (Laravel API mode)', () => {
  it('serialises filters as repeated keys and drops empty values', () => {
    expect(buildQuery({ status: ['received', 'assigned'], page: 2, search: '', clinicId: undefined, openOnly: true })).toBe('?status=received&status=assigned&page=2&openOnly=true');
  });

  it('sends JSON with cookies and the XSRF token from the cookie, never a bearer token', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D%3D; path=/';
    const fetchMock = vi.fn().mockResolvedValue(json({ ok: 1 }));
    vi.stubGlobal('fetch', fetchMock);
    const res = await httpTransport({ method: 'POST', path: '/cases', body: { a: 1 }, token: 'ignored' });
    expect(res).toEqual({ ok: 1 });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = init.headers as Record<string, string>;
    expect(init.credentials).toBe('include');
    expect(headers['X-XSRF-TOKEN']).toBe('abc=='); // URL-decoded, as Laravel expects
    expect(headers['X-Requested-With']).toBe('XMLHttpRequest');
    expect(headers.Authorization).toBeUndefined();
    expect(init.body).toBe('{"a":1}');
  });

  it('reads need no token; the first write without a cookie fetches one from /auth/csrf', async () => {
    const fetchMock = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>(async (url) => {
      if (url.endsWith('/auth/csrf')) {
        document.cookie = 'XSRF-TOKEN=fresh; path=/';
        return new Response(null, { status: 204 });
      }
      return json({ ok: 1 });
    });
    vi.stubGlobal('fetch', fetchMock);
    await httpTransport({ method: 'GET', path: '/cases' });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect((fetchMock.mock.calls[0][1] as RequestInit).headers).not.toHaveProperty('X-XSRF-TOKEN');
    await httpTransport({ method: 'POST', path: '/auth/login', body: {} });
    expect(fetchMock.mock.calls.map((c) => c[0])).toEqual(['/api/cases', '/api/auth/csrf', '/api/auth/login']);
    expect(((fetchMock.mock.calls[2][1] as RequestInit).headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('fresh');
  });

  it('a 419 (expired token) fetches a fresh token and retries once', async () => {
    document.cookie = 'XSRF-TOKEN=stale; path=/';
    let writes = 0;
    const fetchMock = vi.fn(async (url: string, init: RequestInit) => {
      if (url.endsWith('/auth/csrf')) {
        document.cookie = 'XSRF-TOKEN=renewed; path=/';
        return new Response(null, { status: 204 });
      }
      writes++;
      const token = (init.headers as Record<string, string>)['X-XSRF-TOKEN'];
      return token === 'renewed' ? json({ ok: 1 }, 201) : json({ message: 'The page expired. Please try again.' }, 419);
    });
    vi.stubGlobal('fetch', fetchMock);
    expect(await httpTransport({ method: 'POST', path: '/payments', body: { amount: 1 } })).toEqual({ ok: 1 });
    expect(writes).toBe(2);
  });

  it('a second 419 is reported, not retried forever', async () => {
    document.cookie = 'XSRF-TOKEN=x; path=/';
    const fetchMock = vi.fn(async (url: string) => (url.endsWith('/auth/csrf') ? new Response(null, { status: 204 }) : json({ message: 'The page expired. Please try again.' }, 419)));
    vi.stubGlobal('fetch', fetchMock);
    const err = (await httpTransport({ method: 'DELETE', path: '/cases/c1' }).catch((e: unknown) => e)) as ApiError;
    expect(err.status).toBe(419);
    expect(err.message).toBe('The page expired. Please try again.');
    expect(fetchMock.mock.calls.filter((c) => !String(c[0]).endsWith('/auth/csrf'))).toHaveLength(2);
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
