/**
 * Environment for the full-stack end-to-end run: Apache (mod_php) serving the
 * production build exactly as https://lab.sooryoscan.com is served on Hostinger —
 * the folder public_html/48hrs_lab is the (sub)domain's DOCUMENT ROOT, holding the
 * React build, .htaccess and the laravel.php front controller; the app is at "/"
 * and the API at "/api" — and Laravel (backend/) on a disposable MySQL database. Secrets are generated per run and
 * shared with Playwright workers via process.env.
 */
import { randomBytes } from 'node:crypto';
import { chmodSync, existsSync, mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const REPO_ROOT = fileURLToPath(new URL('../../..', import.meta.url));
export const BACKEND = join(REPO_ROOT, 'backend');
export const E2E_PORT = Number(process.env.E2E_PORT ?? 4100);
/** URL path of the app: the subdomain root, as in production. */
export const BASE_PATH = '';
/** Folder names on the server's disk (production layout). They must never appear in a URL. */
export const DOCROOT_FOLDER = '48hrs_lab';
export const APP_FOLDER = '48hrs_lab_app';
export const ORIGIN = `http://localhost:${E2E_PORT}`;
export const BASE_URL = `${ORIGIN}${BASE_PATH}`;

/** App path → URL path (identity at the subdomain root). */
export const app = (path: string) => `${BASE_PATH}${path}`;

/** DB_* from backend/.env, so the run can reach the local MySQL server (override with E2E_DB_*). */
function backendDbEnv(): Record<string, string> {
  const file = join(BACKEND, '.env');
  if (!existsSync(file)) return {};
  const out: Record<string, string> = {};
  for (const line of readFileSync(file, 'utf8').split('\n')) {
    const m = line.match(/^(DB_(?:HOST|PORT|USERNAME|PASSWORD))=(.*)$/);
    if (m) out[m[1]] = m[2].replace(/^"(.*)"$/, '$1');
  }
  return out;
}

export function initStackEnv() {
  const local = backendDbEnv();
  process.env.E2E_DB_HOST ??= local.DB_HOST;
  process.env.E2E_DB_PORT ??= local.DB_PORT;
  process.env.E2E_DB_USERNAME ??= local.DB_USERNAME;
  process.env.E2E_DB_PASSWORD ??= local.DB_PASSWORD;
  const database = process.env.E2E_DB_DATABASE ?? 'dental_lab_e2e';
  if (['dental_lab', 'dental_lab_test'].includes(database)) throw new Error('E2E_DB_DATABASE must be a disposable database: the run wipes it.');
  process.env.E2E_DB_DATABASE = database;
  process.env.E2E_PASSWORD ??= `E2e-${randomBytes(6).toString('hex')}7`;
  process.env.E2E_APP_KEY ??= `base64:${randomBytes(32).toString('base64')}`;
  if (!process.env.E2E_UPLOAD_DIR) {
    process.env.E2E_UPLOAD_DIR = mkdtempSync(join(tmpdir(), '48hrs-e2e-'));
    chmodSync(process.env.E2E_UPLOAD_DIR, 0o777); // Apache runs as www-data
  }
}

/** Everything Laravel needs for the e2e run (Apache SetEnv, and artisan processes). */
export function stackEnv(): Record<string, string> {
  return {
    APP_ENV: 'local',
    APP_DEBUG: 'false',
    APP_KEY: process.env.E2E_APP_KEY!,
    APP_URL: BASE_URL,
    FRONTEND_URL: BASE_URL,
    DB_CONNECTION: 'mysql',
    DB_HOST: process.env.E2E_DB_HOST ?? '127.0.0.1',
    DB_PORT: process.env.E2E_DB_PORT ?? '3306',
    DB_DATABASE: process.env.E2E_DB_DATABASE!,
    DB_USERNAME: process.env.E2E_DB_USERNAME ?? process.env.DB_USERNAME ?? 'lab',
    DB_PASSWORD: process.env.E2E_DB_PASSWORD ?? process.env.DB_PASSWORD ?? '',
    SESSION_DRIVER: 'database',
    SESSION_PATH: '/',
    SESSION_SECURE_COOKIE: 'false',
    CACHE_STORE: 'database',
    QUEUE_CONNECTION: 'database',
    MAIL_MAILER: 'log',
    LOG_CHANNEL: 'stderr',
    LAB_TIMEZONE: 'Africa/Mogadishu',
    UPLOAD_DIR: process.env.E2E_UPLOAD_DIR!,
    SEED_USER_PASSWORD: process.env.E2E_PASSWORD!,
    SEED_MODE: 'demo',
  };
}
