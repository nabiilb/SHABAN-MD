/**
 * Query-string parsing. Express 5 gives string | string[] per key; filters
 * with values outside their allowed set are a 422, not silently ignored.
 */
import type { Request } from 'express';
import type { PageMeta } from '@48hrs/shared/types';
import { isDay } from '@48hrs/shared/dates';
import { validation } from '../lib/errors.ts';

export type Query = Request['query'];

export function qStr(q: Query, key: string): string | undefined {
  const raw = q[key];
  const v = Array.isArray(raw) ? raw[0] : raw;
  if (typeof v !== 'string') return undefined;
  const t = v.trim();
  return t === '' ? undefined : t;
}

export function qList(q: Query, key: string): string[] {
  const raw = q[key];
  const values = (Array.isArray(raw) ? raw : raw === undefined ? [] : [raw]).filter((v): v is string => typeof v === 'string');
  return values.flatMap((v) => v.split(',')).map((v) => v.trim()).filter(Boolean);
}

export function qBool(q: Query, key: string): boolean | undefined {
  const v = qStr(q, key);
  if (v === undefined) return undefined;
  return v === 'true' || v === '1';
}

export function qEnum<T extends string>(q: Query, key: string, allowed: readonly T[]): T | undefined {
  const v = qStr(q, key);
  if (v === undefined) return undefined;
  if (!allowed.includes(v as T)) throw validation({ [key]: [`Must be one of: ${allowed.join(', ')}.`] });
  return v as T;
}

export function qEnumList<T extends string>(q: Query, key: string, allowed: readonly T[]): T[] {
  const values = qList(q, key);
  const bad = values.filter((v) => !allowed.includes(v as T));
  if (bad.length) throw validation({ [key]: [`Unknown value(s): ${bad.join(', ')}.`] });
  return values as T[];
}

/** YYYY-MM-DD, validated. */
export function qDay(q: Query, key: string): string | undefined {
  const v = qStr(q, key);
  if (v === undefined) return undefined;
  if (!isDay(v)) throw validation({ [key]: ['Use a date in YYYY-MM-DD format.'] });
  return v;
}

export const MAX_PER_PAGE = 200;

function positiveInt(v: string | undefined, fallback: number) {
  const n = Number(v);
  return Number.isInteger(n) && n > 0 ? n : fallback;
}

export interface PageRequest {
  page: number;
  perPage: number;
}

export function pageRequest(q: Query, defaultPerPage = 20): PageRequest {
  return { page: positiveInt(qStr(q, 'page'), 1), perPage: Math.min(positiveInt(qStr(q, 'perPage'), defaultPerPage), MAX_PER_PAGE) };
}

/** Clamps the page to the last page (like the mock) and returns skip/take plus the response meta. */
export function paginate(req: PageRequest, total: number): { skip: number; take: number; meta: PageMeta } {
  const lastPage = Math.max(1, Math.ceil(total / req.perPage));
  const page = Math.min(req.page, lastPage);
  return { skip: (page - 1) * req.perPage, take: req.perPage, meta: { page, perPage: req.perPage, total, lastPage } };
}

export function sortDir(q: Query): 'asc' | 'desc' {
  return qStr(q, 'dir') === 'asc' ? 'asc' : 'desc';
}
