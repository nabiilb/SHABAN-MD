import { env } from '@/config/env';
import { ApiError } from './errors';
import { camelKeys, snakeKey, snakeKeys } from './case-transform';
import type { ApiRequest, QueryParams, Transport } from './types';

export function buildQuery(params?: QueryParams) {
  if (!params) return '';
  const qs = new URLSearchParams();
  for (const [rawKey, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue;
    const key = env.snakeCase ? snakeKey(rawKey) : rawKey;
    if (Array.isArray(value)) value.forEach((v) => qs.append(`${key}[]`, String(v)));
    else qs.append(key, String(value));
  }
  const s = qs.toString();
  return s ? `?${s}` : '';
}

async function parseError(res: Response) {
  let body: unknown = null;
  try {
    body = await res.json();
  } catch {
    /* non-JSON error body */
  }
  const parsed = (env.snakeCase ? camelKeys(body) : body) as { message?: string; errors?: Record<string, string[]> } | null;
  return ApiError.fromBody(res.status, parsed ?? undefined);
}

/** Upload via XHR — fetch has no upload progress events. */
function xhrUpload(url: string, req: ApiRequest, headers: Record<string, string>) {
  return new Promise<unknown>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open(req.method, url);
    Object.entries(headers).forEach(([k, v]) => xhr.setRequestHeader(k, v));
    xhr.withCredentials = env.withCredentials;
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
      if (xhr.status >= 200 && xhr.status < 300) resolve(env.snakeCase ? camelKeys(body) : body);
      else reject(ApiError.fromBody(xhr.status, body as { message?: string }));
    };
    xhr.onerror = () => reject(new ApiError(0, ''));
    req.signal?.addEventListener('abort', () => xhr.abort());
    xhr.send(req.formData ?? null);
  });
}

export const httpTransport: Transport = async (req) => {
  const url = `${env.apiUrl}${req.path}${buildQuery(req.params)}`;
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (req.token) headers.Authorization = `Bearer ${req.token}`;

  if (req.formData) return xhrUpload(url, req, headers);

  let body: BodyInit | undefined;
  if (req.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(env.snakeCase ? snakeKeys(req.body) : req.body);
  }

  let res: Response;
  try {
    res = await fetch(url, {
      method: req.method,
      headers,
      body,
      signal: req.signal,
      credentials: env.withCredentials ? 'include' : 'same-origin',
    });
  } catch (e) {
    if ((e as Error).name === 'AbortError') throw e;
    throw new ApiError(0, '');
  }

  if (!res.ok) throw await parseError(res);
  if (req.responseType === 'blob') return res.blob();
  if (res.status === 204) return null;
  const json: unknown = await res.json();
  return env.snakeCase ? camelKeys(json) : json;
};
