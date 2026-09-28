import { round2 } from '@48hrs/shared/billing';

/** Prisma returns DECIMAL columns as Decimal objects; the API speaks plain numbers rounded to cents. */
export function money(v: { toNumber(): number } | number | null | undefined): number {
  if (v === null || v === undefined) return 0;
  return round2(typeof v === 'number' ? v : v.toNumber());
}
