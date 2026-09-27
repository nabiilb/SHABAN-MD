import { ApiError } from './errors';
import { httpTransport } from './http-transport';
import { tokenStore } from './token-store';
import type { ApiRequest, HttpMethod, QueryParams, Transport, UploadProgress } from './types';

let transportPromise: Promise<Transport> | null = null;

/**
 * Picks the transport once. The mock backend is code-split and only loaded when
 * VITE_USE_MOCKS=true, so production bundles talking to a real API never ship it.
 */
function getTransport(): Promise<Transport> {
  if (!transportPromise) {
    // Inline env check (not env.useMocks) so the bundler can drop the mock code in API builds.
    transportPromise = import.meta.env.VITE_USE_MOCKS !== 'false'
      ? import('@/mocks/transport').then((m) => m.mockTransport)
      : Promise.resolve(httpTransport);
  }
  return transportPromise;
}

type UnauthorizedHandler = () => void;
let onUnauthorized: UnauthorizedHandler | null = null;

/** The auth store registers this so any 401 from any request logs the user out. */
export function setUnauthorizedHandler(fn: UnauthorizedHandler | null) {
  onUnauthorized = fn;
}

async function send<T>(req: Omit<ApiRequest, 'token'>): Promise<T> {
  const transport = await getTransport();
  const session = tokenStore.get();
  try {
    return (await transport({ ...req, token: session?.token ?? null })) as T;
  } catch (err) {
    if (err instanceof ApiError && err.isUnauthorized && !req.path.startsWith('/auth/login')) onUnauthorized?.();
    throw err;
  }
}

interface Opts {
  params?: QueryParams;
  signal?: AbortSignal;
}

function call<T>(method: HttpMethod, path: string, body?: unknown, opts: Opts = {}) {
  return send<T>({ method, path, body, params: opts.params, signal: opts.signal });
}

export const api = {
  get: <T>(path: string, opts?: Opts) => call<T>('GET', path, undefined, opts),
  post: <T>(path: string, body?: unknown, opts?: Opts) => call<T>('POST', path, body, opts),
  put: <T>(path: string, body?: unknown, opts?: Opts) => call<T>('PUT', path, body, opts),
  patch: <T>(path: string, body?: unknown, opts?: Opts) => call<T>('PATCH', path, body, opts),
  delete: <T>(path: string, opts?: Opts) => call<T>('DELETE', path, undefined, opts),
  upload: <T>(path: string, formData: FormData, onUploadProgress?: (p: UploadProgress) => void, signal?: AbortSignal) =>
    send<T>({ method: 'POST', path, formData, onUploadProgress, signal }),
  download: (path: string) => send<Blob>({ method: 'GET', path, responseType: 'blob' }),
};
