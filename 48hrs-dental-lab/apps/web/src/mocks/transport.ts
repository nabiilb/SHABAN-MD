/**
 * In-browser mock backend. Every request goes through the same auth,
 * permission and validation checks a real API would perform, so the UI is
 * exercised against realistic 401/403/404/409/422 responses.
 */
import { env } from '@/config/env';
import { ApiError } from '@/services/api/errors';
import type { Transport } from '@/services/api/types';
import { getDb, persist } from './db';
import { runDeadlineScan } from './domain';
import { match } from './router';
import './handlers/auth';
import './handlers/cases';
import './handlers/directory';
import './handlers/finance';
import './handlers/lab';
import './handlers/notifications';
import './handlers/reports';
import './handlers/admin';

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/** Responses are deep-copied so UI code can never mutate the "database". */
function clone<T>(v: T): T {
  if (v === null || v === undefined || v instanceof Blob) return v;
  return JSON.parse(JSON.stringify(v)) as T;
}

export const mockTransport: Transport = async (req) => {
  if (env.mockLatencyMs > 0) await sleep(env.mockLatencyMs * (0.6 + Math.random() * 0.8));
  if (req.signal?.aborted) throw new DOMException('Aborted', 'AbortError');

  const db = getDb();
  const now = Date.now();
  runDeadlineScan(db, now);

  const { handler, params } = match(req.method, req.path.split('?')[0]);
  try {
    const result = await handler({
      db,
      params,
      query: req.params ?? {},
      body: clone(req.body),
      formData: req.formData,
      token: req.token ?? null,
      now,
      onUploadProgress: req.onUploadProgress,
    });
    return clone(result);
  } catch (err) {
    if (err instanceof ApiError) throw err;
    console.error('[mock api]', err);
    throw new ApiError(500, 'Unexpected server error.');
  } finally {
    persist();
  }
};
