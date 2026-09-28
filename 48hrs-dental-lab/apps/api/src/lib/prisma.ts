import { PrismaPg } from '@prisma/adapter-pg';
import { PrismaClient } from '../generated/prisma/client.ts';
import { env } from '../config/env.ts';

export type Db = PrismaClient;
/** The client inside $transaction(async (tx) => …). */
export type Tx = Parameters<Parameters<PrismaClient['$transaction']>[0]>[0];
/** Either the root client or a transaction client. */
export type DbOrTx = Db | Tx;

export const prisma: Db = new PrismaClient({ adapter: new PrismaPg({ connectionString: env.DATABASE_URL }) });

export async function disconnect() {
  await prisma.$disconnect();
}
