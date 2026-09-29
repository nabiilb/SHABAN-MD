import { execFileSync } from 'node:child_process';
import { expect, type APIResponse, type Page } from '@playwright/test';
import mysql from 'mysql2/promise';
import { app, BACKEND, stackEnv } from './stack-env';

export const PASSWORD = process.env.E2E_PASSWORD!;

const env = stackEnv();
/** Direct database access for things a browser cannot do: move a deadline into the past, end a session. */
export const db = mysql.createPool({ host: env.DB_HOST, port: Number(env.DB_PORT), user: env.DB_USERNAME, password: env.DB_PASSWORD, database: env.DB_DATABASE, connectionLimit: 2, timezone: 'Z', dateStrings: false });

/** One row (or undefined) from a query. */
export async function one<T = Record<string, unknown>>(sql: string, params: unknown[] = []): Promise<T> {
  const [rows] = await db.query(sql, params);
  return (rows as T[])[0];
}

export async function all<T = Record<string, unknown>>(sql: string, params: unknown[] = []): Promise<T[]> {
  const [rows] = await db.query(sql, params);
  return rows as T[];
}

export async function signIn(page: Page, email: string) {
  await page.goto(app('/logout'));
  await page.waitForURL('**/login');
  await page.fill('input[type=email]', email);
  await page.fill('input[autocomplete=current-password]', PASSWORD);
  await page.click('button[type=submit]');
  await page.waitForURL('**/dashboard');
}

/** A write through the API with the page's session, sending the CSRF token as the web app does. */
export async function apiWrite(page: Page, method: 'post' | 'put' | 'patch' | 'delete', path: string, data: unknown = {}): Promise<APIResponse> {
  const cookies = await page.context().cookies();
  const token = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
  return page.request[method](app(path), { headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' }, data });
}

export const dialog = (page: Page) => page.getByRole('dialog');
export const act = (page: Page, label: string) => page.getByRole('button', { name: label, exact: true }).first().click();
export const toast = (page: Page, text: string | RegExp) => expect(page.getByText(text).first()).toBeVisible();

/** One deadline scan, exactly as the cron job runs it (php artisan lab:scan-deadlines). */
export function runDeadlineScan() {
  execFileSync('php', ['artisan', 'lab:scan-deadlines'], { cwd: BACKEND, env: { ...process.env, ...stackEnv() }, stdio: 'pipe' });
}

/**
 * Ends a user's sessions as if their absolute end (SESSION_TTL_MINUTES) had passed:
 * rewrites lab.session_expires_at inside the stored session payload (base64 JSON).
 */
export async function expireSessions(userId: string) {
  const rows = await all<{ id: string; payload: string }>('SELECT id, payload FROM sessions WHERE user_id = ?', [userId]);
  let ended = 0;
  for (const r of rows) {
    // Laravel stores the session as base64(JSON); session()->put('lab.session_expires_at') nests it under "lab".
    const data = JSON.parse(Buffer.from(r.payload, 'base64').toString('utf8')) as { lab?: { session_expires_at?: number } };
    if (!data.lab?.session_expires_at) continue; // not a signed-in session
    data.lab.session_expires_at = Date.now() - 1000;
    await db.query('UPDATE sessions SET payload = ? WHERE id = ?', [Buffer.from(JSON.stringify(data)).toString('base64'), r.id]);
    ended++;
  }
  return ended;
}

/** Registers a case at reception through the UI and returns its URL and number. */
export async function registerCase(page: Page, { clinic = 'Smile Dental Clinic', doctor = 'Dr. Amina Yusuf', patient = 'E2E Patient', teeth = [3, 4] } = {}) {
  await page.goto(app('/cases/new'));
  await page.getByRole('combobox').first().click();
  await page.locator('[cmdk-item]', { hasText: clinic }).click();
  await page.getByRole('combobox').nth(1).click();
  await page.locator('[cmdk-item]', { hasText: doctor }).click();
  await page.getByRole('radio', { name: 'New patient' }).click();
  await page.getByPlaceholder('Ahmed Mohamed').fill(patient);
  for (const t of teeth) await page.getByRole('button', { name: `Tooth ${t}`, exact: true }).click();
  await page.getByRole('button', { name: 'Register case' }).first().click();
  await page.waitForURL(/\/cases\/(?!new)[\w-]+$/);
  const caseNumber = (await page.locator('header h1', { hasText: /^DL-/ }).innerText()).trim();
  return { url: page.url(), caseNumber, id: page.url().split('/').pop()! };
}
