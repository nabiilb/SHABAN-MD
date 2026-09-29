import { env } from '@/config/env';
import { ApiError } from './errors';
import { serverClock } from './server-clock';
import type { ApiRequest, QueryParams, Transport } from './types';

/**
 * Transport for the Laravel API (backend/). Authentication rides on the
 * HTTP-only session cookie set by the API, so requests are sent with
 * credentials and no session token is ever readable from JavaScript.
 *
 * CSRF: Laravel sets a readable XSRF-TOKEN cookie; every state-changing
 * request echoes it in the X-XSRF-TOKEN header. Before the first write (no
 * cookie yet) the transport fetches one from GET /auth/csrf, and when the API
 * answers 419 (token rotated or expired) it fetches a fresh one and retries once.
 */

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

/** The XSRF-TOKEN cookie Laravel sets (URL-encoded), or null. */
export function readXsrfCookie(): string | null {
  if (typeof document === 'undefined') return null;
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return m ? decodeURIComponent(m[1]) : null;
}

let csrfPromise: Promise<void> | null = null;

/** Asks the API for a CSRF cookie (once, however many requests need it together). */
function fetchCsrfCookie(): Promise<void> {
  csrfPromise ??= fetch(`${env.apiUrl}/auth/csrf`, { method: 'GET', credentials: 'include', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    .then(() => undefined)
    .catch(() => undefined)
    .finally(() => {
      csrfPromise = null;
    });
  return csrfPromise;
}

async function csrfHeader(method: string): Promise<Record<string, string>> {
  if (SAFE_METHODS.has(method)) return {};
  if (!readXsrfCookie()) await fetchCsrfCookie();
  const token = readXsrfCookie();
  return token ? { 'X-XSRF-TOKEN': token } : {};
}

/** Arrays are sent as repeated keys: ?status=received&status=assigned */
export function buildQuery(params?: QueryParams) {
  if (!params) return '';
  const qs = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue;
    if (Array.isArray(value)) value.forEach((v) => qs.append(key, String(v)));
    else qs.append(key, String(value));
  }
  const s = qs.toString();
  return s ? `?${s}` : '';
}

async function parseError(res: Response) {
  let body: { message?: string; errors?: Record<string, string[]> } | null = null;
  try {
    body = (await res.json()) as typeof body;
  } catch {
    /* non-JSON error body */
  }
  return ApiError.fromBody(res.status, body);
}

/** Upload via XHR — fetch has no upload progress events. */
function xhrUpload(url: string, req: ApiRequest, headers: Record<string, string>) {
  return new Promise<unknown>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open(req.method, url);
    Object.entries(headers).forEach(([k, v]) => xhr.setRequestHeader(k, v));
    xhr.withCredentials = true;
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) req.onUploadProgress?.({ loaded: e.loaded, total: e.total, percent: Math.round((e.loaded / e.total) * 100) });
    };
    xhr.onload = () => {
      let body: unknown = null;
      try {
        body = xhr.responseText ? JSON.parse(xhr.responseText) : null;
      } catch {
        /* ignore */
      }
      if (xhr.status >= 200 && xhr.status < 300) resolve(body);
      else reject(ApiError.fromBody(xhr.status, body as { message?: string }));
    };
    xhr.onerror = () => reject(new ApiError(0, ''));
    req.signal?.addEventListener('abort', () => xhr.abort());
    xhr.send(req.formData ?? null);
  });
}

async function send(req: ApiRequest, url: string): Promise<{ res?: Response; upload?: unknown }> {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(await csrfHeader(req.method)) };

  if (req.formData) return { upload: await xhrUpload(url, req, headers) };

  let body: BodyInit | undefined;
  if (req.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(req.body);
  }

  let res: Response;
  const sentAt = Date.now();
  try {
    res = await fetch(url, { method: req.method, headers, body, signal: req.signal, credentials: 'include' });
  } catch (e) {
    if ((e as Error).name === 'AbortError') throw e;
    throw new ApiError(0, '');
  }
  serverClock.observe(res.headers.get('X-Server-Time'), sentAt, Date.now());
  return { res };
}

async function finish(req: ApiRequest, res: Response) {
  if (!res.ok) throw await parseError(res);
  if (req.responseType === 'blob') return res.blob();
  if (res.status === 204) return null;
  return res.json();
}

export const httpTransport: Transport = async (req) => {
  const url = `${env.apiUrl}${req.path}${buildQuery(req.params)}`;
  try {
    const first = await send(req, url);
    if (!first.res) return first.upload;
    if (first.res.status !== 419 || SAFE_METHODS.has(req.method)) return await finish(req, first.res);
  } catch (err) {
    if (!(err instanceof ApiError) || err.status !== 419) throw err;
  }
  // 419: the CSRF token expired or was rotated (e.g. after signing in elsewhere). Get a fresh one and retry once.
  await fetchCsrfCookie();
  const retry = await send(req, url);
  return retry.res ? finish(req, retry.res) : retry.upload;
};
