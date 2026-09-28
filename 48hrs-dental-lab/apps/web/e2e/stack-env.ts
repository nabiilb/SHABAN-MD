/**
 * Environment for the full-stack end-to-end run: the real API (serving the
 * built web app on one origin) on a disposable PostgreSQL database. Secrets
 * are generated per run and shared with Playwright workers via process.env.
 */
import { randomBytes } from 'node:crypto';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadEnv } from 'vite';

export const REPO_ROOT = fileURLToPath(new URL('../../..', import.meta.url));
export const E2E_PORT = Number(process.env.E2E_PORT ?? 4100);
export const BASE_URL = `http://localhost:${E2E_PORT}`;

export function initStackEnv() {
  const fileEnv = loadEnv('e2e', REPO_ROOT, '');
  const db = process.env.E2E_DATABASE_URL ?? fileEnv.E2E_DATABASE_URL;
  if (!db) throw new Error('Set E2E_DATABASE_URL (a disposable PostgreSQL database) to run the end-to-end tests.');
  if (db === (process.env.DATABASE_URL ?? fileEnv.DATABASE_URL)) throw new Error('E2E_DATABASE_URL must not be the development database: the run wipes it.');
  process.env.E2E_DATABASE_URL = db;
  process.env.E2E_PASSWORD ??= `E2e-${randomBytes(6).toString('hex')}7`;
  process.env.E2E_JWT_SECRET ??= randomBytes(32).toString('base64');
  process.env.E2E_UPLOAD_DIR ??= mkdtempSync(join(tmpdir(), '48hrs-e2e-'));
}

/** Everything the API and the worker need for the e2e run. */
export function stackEnv(): Record<string, string> {
  return {
    NODE_ENV: 'test',
    PORT: String(E2E_PORT),
    DATABASE_URL: process.env.E2E_DATABASE_URL!,
    JWT_SECRET: process.env.E2E_JWT_SECRET!,
    WEB_DIST_DIR: '../web/dist-e2e',
    COOKIE_SECURE: 'false',
    CORS_ORIGINS: BASE_URL,
    LAB_TIMEZONE: 'Africa/Mogadishu',
    UPLOAD_DIR: process.env.E2E_UPLOAD_DIR!,
    MAIL_TRANSPORT: 'log',
    APP_URL: BASE_URL,
    SEED_USER_PASSWORD: process.env.E2E_PASSWORD!,
    SEED_MODE: 'demo',
  };
}
