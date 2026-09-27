import { ApiError } from '@/services/api/errors';
import type { HttpMethod, QueryParams, UploadProgress } from '@/services/api/types';
import type { AuthSession } from '@/types/models';
import type { MockDatabase, MockUser } from './db';

export interface MockContext {
  db: MockDatabase;
  params: Record<string, string>;
  query: QueryParams;
  body: unknown;
  formData?: FormData;
  token: string | null;
  now: number;
  onUploadProgress?: (p: UploadProgress) => void;
}

export interface AuthedContext extends MockContext {
  user: MockUser;
  permissions: string[];
  session: Pick<AuthSession, 'token' | 'expiresAt'>;
}

type Handler = (ctx: MockContext) => unknown | Promise<unknown>;

interface Route {
  method: HttpMethod;
  pattern: RegExp;
  keys: string[];
  handler: Handler;
}

const routes: Route[] = [];

export function route(method: HttpMethod, path: string, handler: Handler) {
  const keys: string[] = [];
  const pattern = new RegExp(
    '^' +
      path.replace(/\/:([a-zA-Z]+)/g, (_, k: string) => {
        keys.push(k);
        return '/([^/]+)';
      }) +
      '$',
  );
  routes.push({ method, pattern, keys, handler });
}

export function match(method: HttpMethod, path: string) {
  for (const r of routes) {
    if (r.method !== method) continue;
    const m = r.pattern.exec(path);
    if (!m) continue;
    const params: Record<string, string> = {};
    r.keys.forEach((k, i) => (params[k] = decodeURIComponent(m[i + 1])));
    return { handler: r.handler, params };
  }
  throw new ApiError(404, `No mock route for ${method} ${path}`);
}

/* ---------------------------- Query helpers ---------------------------- */

export function qStr(q: QueryParams, key: string): string | undefined {
  const v = q[key];
  if (v === undefined || v === null || v === '') return undefined;
  return Array.isArray(v) ? String(v[0]) : String(v);
}

export function qNum(q: QueryParams, key: string, fallback: number) {
  const n = Number(qStr(q, key));
  return Number.isFinite(n) && n > 0 ? n : fallback;
}

export function qBool(q: QueryParams, key: string): boolean | undefined {
  const v = qStr(q, key);
  if (v === undefined) return undefined;
  return v === 'true' || v === '1';
}

export function qList(q: QueryParams, key: string): string[] {
  const v = q[key];
  if (v === undefined || v === null || v === '') return [];
  return Array.isArray(v) ? v.map(String) : String(v).split(',');
}

export function paginate<T>(items: T[], q: QueryParams) {
  const perPage = Math.min(qNum(q, 'perPage', 20), 200);
  const total = items.length;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const page = Math.min(qNum(q, 'page', 1), lastPage);
  return { data: items.slice((page - 1) * perPage, page * perPage), meta: { page, perPage, total, lastPage } };
}

export function sortItems<T>(items: T[], q: QueryParams, accessors: Record<string, (t: T) => string | number | null | undefined>, fallback?: string) {
  const key = qStr(q, 'sort') ?? fallback;
  const get = key ? accessors[key] : undefined;
  if (!get) return items;
  const dir = qStr(q, 'dir') === 'asc' ? 1 : -1;
  return [...items].sort((a, b) => {
    const va = get(a);
    const vb = get(b);
    if (va === vb) return 0;
    if (va === null || va === undefined) return 1;
    if (vb === null || vb === undefined) return -1;
    return (va < vb ? -1 : 1) * dir;
  });
}

export function includesText(haystack: (string | null | undefined)[], needle: string | undefined) {
  if (!needle) return true;
  const n = needle.trim().toLowerCase();
  return haystack.some((h) => h?.toLowerCase().includes(n));
}

/* ------------------------------ Validation ----------------------------- */

export type FieldErrors = Record<string, string[]>;

export function validationError(errors: FieldErrors, message = 'Some fields need attention.') {
  return new ApiError(422, message, errors);
}

export function requireFields(body: Record<string, unknown>, fields: Record<string, string>) {
  const errors: FieldErrors = {};
  for (const [k, label] of Object.entries(fields)) {
    const v = body[k];
    if (v === undefined || v === null || (typeof v === 'string' && v.trim() === '')) errors[k] = [`${label} is required.`];
  }
  if (Object.keys(errors).length) throw validationError(errors);
}

export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
