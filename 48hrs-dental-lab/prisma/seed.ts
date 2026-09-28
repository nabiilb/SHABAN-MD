/**
 * npm run db:seed  (prisma db seed)
 *
 * SEED_MODE=demo (default outside production): replaces all data with the demo
 *   dataset; every demo account gets SEED_USER_PASSWORD.
 * SEED_MODE=base (default in production): permission catalogue, default roles,
 *   settings and sequences only; creates the first Super Admin from
 *   SEED_ADMIN_EMAIL / SEED_ADMIN_NAME / SEED_ADMIN_PASSWORD when set.
 */
import { passwordSchema } from '@48hrs/shared/validation';
import { prisma } from '../apps/api/src/lib/prisma.ts';
import { seedAdmin, seedBase, seedDemo } from '../apps/api/src/db/seed.ts';

function requirePassword(name: string) {
  const value = process.env[name];
  const parsed = passwordSchema.safeParse(value ?? '');
  if (!parsed.success) throw new Error(`${name} must be set to a password of at least 8 characters with letters and numbers.`);
  return parsed.data;
}

async function main() {
  const production = process.env.NODE_ENV === 'production';
  const mode = process.env.SEED_MODE ?? (production ? 'base' : 'demo');

  if (mode === 'demo') {
    if (production && process.env.ALLOW_DEMO_SEED !== 'true') throw new Error('Refusing to load demo data in production (set ALLOW_DEMO_SEED=true to override).');
    const result = await seedDemo(prisma, { password: requirePassword('SEED_USER_PASSWORD') });
    console.log(`Demo data loaded: ${result.users} users, ${result.cases} cases, ${result.invoices} invoices, ${result.attachments} files.`);
    return;
  }

  if (mode !== 'base') throw new Error(`Unknown SEED_MODE "${mode}" (use demo or base).`);
  await seedBase(prisma);
  console.log('Permission catalogue, roles, settings and sequences are in place.');
  if (process.env.SEED_ADMIN_EMAIL) {
    const created = await seedAdmin(prisma, {
      email: process.env.SEED_ADMIN_EMAIL,
      name: process.env.SEED_ADMIN_NAME || 'Super Admin',
      password: requirePassword('SEED_ADMIN_PASSWORD'),
    });
    console.log(created ? `Super Admin ${process.env.SEED_ADMIN_EMAIL} created.` : `Super Admin ${process.env.SEED_ADMIN_EMAIL} already exists.`);
  }
}

main()
  .catch((err: unknown) => {
    console.error(err instanceof Error ? err.message : err);
    process.exitCode = 1;
  })
  .finally(() => prisma.$disconnect());
