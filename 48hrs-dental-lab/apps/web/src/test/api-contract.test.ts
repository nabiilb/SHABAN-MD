/**
 * The web app ↔ Laravel API contract in API mode (independent of the mock backend):
 * the base URL always ends in /api, the HTTP transport builds URLs under it, the
 * app can live in a sub-folder, and every endpoint the services call exists on the
 * API router (backend/routes/api-manifest.json, kept current by a PHP test).
 */
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DEFAULT_API_BASE, resolveApiBase } from '@/config/api-base';
import { resolveBasePath, routerBasename } from '@/config/base-path';

const root = join(__dirname, '..', '..');

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.resetModules();
});

describe('API base URL', () => {
  it('defaults to same-origin /api and normalises trailing slashes', () => {
    expect(DEFAULT_API_BASE).toBe('/api');
    expect(resolveApiBase(undefined)).toBe('/api');
    expect(resolveApiBase('')).toBe('/api');
    expect(resolveApiBase('/api/')).toBe('/api');
    expect(resolveApiBase('https://api.example.com/api')).toBe('https://api.example.com/api');
    expect(resolveApiBase('https://lab.example.com/backend/api//')).toBe('https://lab.example.com/backend/api');
    expect(resolveApiBase('/lab/api')).toBe('/lab/api'); // a deployment under a URL sub-path
  });

  it('refuses a base that would send requests outside /api (e.g. /cases on the API root)', () => {
    for (const bad of ['https://api.example.com', 'https://api.example.com/', '/', '/v1', 'http://127.0.0.1:8000', 'ftp://x/api', '/apis']) {
      expect(() => resolveApiBase(bad), bad).toThrow(/VITE_API_URL/);
    }
  });
});

describe('base path (VITE_BASE_PATH)', () => {
  it('serves from the root by default (production) or from a URL sub-path such as /lab/', () => {
    expect(resolveBasePath(undefined)).toBe('/');
    expect(resolveBasePath('/')).toBe('/');
    expect(resolveBasePath('/lab')).toBe('/lab/');
    expect(resolveBasePath('/lab/')).toBe('/lab/');
    expect(routerBasename('/')).toBe('');
    expect(routerBasename('/lab/')).toBe('/lab');
  });

  it('refuses relative or URL-like base paths', () => {
    for (const bad of ['lab', 'https://x.com/lab/', '/a//b', '/lab?x=1', '/lab lab']) {
      expect(() => resolveBasePath(bad), bad).toThrow(/VITE_BASE_PATH/);
    }
  });
});

async function transportWith(base: string | undefined) {
  if (base !== undefined) vi.stubEnv('VITE_API_URL', base);
  const fetchMock = vi.fn(async () => new Response(JSON.stringify({ ok: true }), { status: 200, headers: { 'Content-Type': 'application/json', 'X-Server-Time': String(Date.now()) } }));
  vi.stubGlobal('fetch', fetchMock);
  vi.resetModules();
  const { httpTransport } = await import('@/services/api/http-transport');
  return { httpTransport, fetchMock };
}

describe('HTTP transport (VITE_USE_MOCKS=false)', () => {
  it('a sub-path API base is used for every request', async () => {
    const { httpTransport, fetchMock } = await transportWith('/lab/api');
    await httpTransport({ method: 'GET', path: '/cases/c1' });
    expect((fetchMock.mock.calls as unknown as [string][])[0][0]).toBe('/lab/api/cases/c1');
  });

  it('without VITE_API_URL every request goes to /api/…, with cookies and the CSRF header', async () => {
    document.cookie = 'XSRF-TOKEN=tok; path=/';
    const { httpTransport, fetchMock } = await transportWith(undefined);
    await httpTransport({ method: 'GET', path: '/cases', params: { status: ['received', 'assigned'], search: 'DL-1', empty: '' } });
    await httpTransport({ method: 'POST', path: '/cases/c1/status', body: { status: 'review' } });
    const [[url1, init1], [url2, init2]] = fetchMock.mock.calls as unknown as [string, RequestInit][];
    expect(url1).toBe('/api/cases?status=received&status=assigned&search=DL-1');
    expect(url2).toBe('/api/cases/c1/status');
    for (const init of [init1, init2]) {
      expect(init.credentials).toBe('include');
      expect((init.headers as Record<string, string>)['X-Requested-With']).toBe('XMLHttpRequest');
    }
    expect(init2.body).toBe(JSON.stringify({ status: 'review' }));
    expect((init2.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('tok');
    expect((init1.headers as Record<string, string>)['X-XSRF-TOKEN']).toBeUndefined();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
  });

  it('a configured VITE_API_URL on another origin is used as-is', async () => {
    const { httpTransport, fetchMock } = await transportWith('https://api.example.com/api/');
    await httpTransport({ method: 'GET', path: '/auth/me' });
    expect((fetchMock.mock.calls as unknown as [string][])[0][0]).toBe('https://api.example.com/api/auth/me');
  });
});

describe('every endpoint the web app calls exists on the API router', () => {
  const MOCK_ONLY = new Set(['POST /settings/reset-demo']); // demo-data reset: UI only rendered when VITE_USE_MOCKS=true
  const METHOD: Record<string, string> = { get: 'GET', post: 'POST', put: 'PUT', patch: 'PATCH', delete: 'DELETE', upload: 'POST', download: 'GET' };

  function clientCalls() {
    const dir = join(root, 'src', 'services');
    const calls: { method: string; path: string; file: string }[] = [];
    let total = 0;
    for (const file of readdirSync(dir).filter((f) => f.endsWith('.ts'))) {
      const src = readFileSync(join(dir, file), 'utf8');
      total += [...src.matchAll(/\bapi\.(get|post|put|patch|delete|upload|download)\b/g)].length;
      for (const m of src.matchAll(/\bapi\.(get|post|put|patch|delete|upload|download)(?:<[^\n'`]*?>)?\(\s*[`']([^`']+)[`']/g)) {
        calls.push({ method: METHOD[m[1]], path: m[2].replace(/\$\{[^}]+\}/g, ':p'), file });
      }
    }
    return { calls, total };
  }

  function apiRoutes() {
    const manifest = readFileSync(join(root, '..', '..', 'backend', 'routes', 'api-manifest.json'), 'utf8');
    return JSON.parse(manifest) as { method: string; path: string }[];
  }

  it('checks every api.* call in the services (all use a literal path) and the whole router', () => {
    const { calls, total } = clientCalls();
    expect(calls.length).toBe(total);
    expect(total).toBeGreaterThan(60);
    expect(apiRoutes().length).toBeGreaterThan(60);
  });

  it('paths are relative to the base (start with /, never repeat /api) and all resolve to a route', () => {
    const routes = apiRoutes();
    const matches = (call: { method: string; path: string }, route: { method: string; path: string }) => {
      if (call.method !== route.method) return false;
      const a = call.path.split('?')[0].split('/');
      const b = route.path.split('/');
      return a.length === b.length && a.every((seg, i) => seg === b[i] || seg === ':p' || (b[i].startsWith(':') && seg !== ''));
    };
    const missing: string[] = [];
    for (const call of clientCalls().calls) {
      expect(call.path.startsWith('/'), `${call.file}: ${call.path}`).toBe(true);
      expect(call.path.startsWith('/api/'), `${call.file}: ${call.path}`).toBe(false);
      const key = `${call.method} ${call.path}`;
      if (!MOCK_ONLY.has(key) && !routes.some((r) => matches(call, r))) missing.push(`${key} (${call.file})`);
    }
    expect(missing).toEqual([]);
  });
});
