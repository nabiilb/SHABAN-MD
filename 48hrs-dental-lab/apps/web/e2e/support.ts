import { execFileSync } from 'node:child_process';
import { expect, type Page } from '@playwright/test';
import pg from 'pg';
import { stackEnv } from './stack-env';

export const PASSWORD = process.env.E2E_PASSWORD!;

/** Direct database access for things a browser cannot do: move a deadline into the past, end a session. */
export const db = new pg.Pool({ connectionString: process.env.E2E_DATABASE_URL, max: 2 });

export async function signIn(page: Page, email: string) {
  await page.goto('/logout');
  await page.waitForURL('**/login');
  await page.fill('input[type=email]', email);
  await page.fill('input[autocomplete=current-password]', PASSWORD);
  await page.click('button[type=submit]');
  await page.waitForURL('**/dashboard');
}

export const dialog = (page: Page) => page.getByRole('dialog');
export const act = (page: Page, label: string) => page.getByRole('button', { name: label, exact: true }).first().click();
export const toast = (page: Page, text: string | RegExp) => expect(page.getByText(text).first()).toBeVisible();

/** One pass of the deadline worker, exactly as `npm run worker:once` runs it. */
export function runWorkerOnce() {
  execFileSync('npx', ['tsx', '../api/src/jobs/worker.ts', '--once'], { env: { ...process.env, ...stackEnv() }, stdio: 'pipe' });
}

/** Registers a case at reception through the UI and returns its URL and number. */
export async function registerCase(page: Page, { clinic = 'Smile Dental Clinic', doctor = 'Dr. Amina Yusuf', patient = 'E2E Patient', teeth = [3, 4] } = {}) {
  await page.goto('/cases/new');
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
