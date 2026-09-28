import { randomBytes } from 'node:crypto';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { loadEnv } from 'vite';
import { defineConfig } from 'vitest/config';

/**
 * API tests run against a real PostgreSQL database: TEST_DATABASE_URL (never the
 * development DATABASE_URL — every suite wipes and re-seeds it). Secrets used
 * by the tests are generated per run, not stored anywhere.
 */
const root = new URL('../..', import.meta.url).pathname;
const fileEnv = loadEnv('test', root, '');
const testDb = process.env.TEST_DATABASE_URL ?? fileEnv.TEST_DATABASE_URL;
if (!testDb) throw new Error('Set TEST_DATABASE_URL (a disposable PostgreSQL database) to run the API tests.');
if (testDb === (process.env.DATABASE_URL ?? fileEnv.DATABASE_URL)) throw new Error('TEST_DATABASE_URL must not be the development database: the tests wipe it.');
process.env.TEST_DATABASE_URL = testDb; // for global-setup (runs in this process)

export default defineConfig({
  test: {
    environment: 'node',
    include: ['test/**/*.test.ts'],
    globalSetup: ['./test/global-setup.ts'],
    setupFiles: ['../../packages/shared/test/fixed-clock.ts'],
    // One database: suites run one after another.
    fileParallelism: false,
    testTimeout: 30_000,
    hookTimeout: 60_000,
    env: {
      NODE_ENV: 'test',
      DATABASE_URL: testDb,
      TEST_DATABASE_URL: testDb,
      JWT_SECRET: randomBytes(32).toString('base64'),
      TEST_USER_PASSWORD: `Lab-${randomBytes(6).toString('hex')}9`,
      COOKIE_SECURE: 'false',
      CORS_ORIGINS: 'http://localhost:5173',
      LAB_TIMEZONE: 'Africa/Mogadishu',
      UPLOAD_DIR: mkdtempSync(join(tmpdir(), '48hrs-uploads-')),
      MAIL_TRANSPORT: 'log',
      APP_URL: 'http://localhost:5173',
      ACCESS_TOKEN_TTL_MINUTES: '15',
      SESSION_TTL_MINUTES: '480',
      LOGIN_MAX_ATTEMPTS: '5',
      LOGIN_LOCK_MINUTES: '15',
      MAX_UPLOAD_MB: '1', // small, so the size limit is exercised cheaply
      AUTH_RATE_LIMIT: '1000', // the suites sign in often; auth-rate-limit.test.ts runs with a low limit
    },
  },
});
