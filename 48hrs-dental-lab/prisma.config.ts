import 'dotenv/config';
import { defineConfig } from 'prisma/config';

// `prisma generate` does not need a database, so a missing DATABASE_URL only
// fails the commands that connect (migrate, seed, studio).

export default defineConfig({
  schema: 'prisma/schema.prisma',
  migrations: {
    path: 'prisma/migrations',
    seed: 'tsx prisma/seed.ts',
  },
  datasource: {
    url: process.env.DATABASE_URL ?? '',
  },
});
