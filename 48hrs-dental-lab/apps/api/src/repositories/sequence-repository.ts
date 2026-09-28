import type { DbOrTx } from '../lib/prisma.ts';

export const SEQUENCES = { case: 'case_number', invoice: 'invoice_number', patient: 'patient_code' } as const;
export type SequenceName = (typeof SEQUENCES)[keyof typeof SEQUENCES];

/**
 * Next value of a counter. The UPDATE takes a row lock, so concurrent
 * transactions get distinct numbers; the row is created on first use.
 */
export async function nextSequence(db: DbOrTx, name: SequenceName): Promise<number> {
  const row = await db.sequence.upsert({ where: { name }, create: { name, value: 1 }, update: { value: { increment: 1 } } });
  return row.value;
}
