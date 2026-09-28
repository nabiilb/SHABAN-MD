/**
 * Sorting by a computed SQL expression (e.g. an invoice's remaining balance or
 * a patient's latest case) that Prisma's orderBy cannot express. The caller
 * filters with Prisma, then this orders just the matching ids in PostgreSQL
 * and returns one page of them, in order.
 */
import { Prisma } from '../generated/prisma/client.ts';
import type { DbOrTx } from '../lib/prisma.ts';

export type Direction = 'asc' | 'desc';

export const dirSql = (dir: Direction) => Prisma.raw(dir === 'asc' ? 'ASC' : 'DESC');

export async function orderedIdPage(db: DbOrTx, query: (ids: string[]) => Prisma.Sql, ids: string[], skip: number, take: number): Promise<string[]> {
  if (!ids.length) return [];
  const rows = await db.$queryRaw<{ id: string }[]>(Prisma.sql`${query(ids)} LIMIT ${take} OFFSET ${skip}`);
  return rows.map((r) => r.id);
}

/** Re-orders rows fetched with `id IN (...)` to match the id page. */
export function inIdOrder<T extends { id: string }>(rows: T[], ids: string[]) {
  const pos = new Map(ids.map((id, i) => [id, i]));
  return [...rows].sort((a, b) => (pos.get(a.id) ?? 0) - (pos.get(b.id) ?? 0));
}

/** In-memory sort for small tables (doctors, clinics, technicians, users) sorted by derived figures. */
export function sortBy<T>(items: T[], get: ((t: T) => string | number | null | undefined) | undefined, dir: Direction) {
  if (!get) return items;
  const m = dir === 'asc' ? 1 : -1;
  return [...items].sort((a, b) => {
    const va = get(a);
    const vb = get(b);
    if (va === vb) return 0;
    if (va === null || va === undefined) return 1;
    if (vb === null || vb === undefined) return -1;
    if (typeof va === 'string' && typeof vb === 'string') return va.localeCompare(vb, 'en', { sensitivity: 'base' }) * m;
    return (va < vb ? -1 : 1) * m;
  });
}
