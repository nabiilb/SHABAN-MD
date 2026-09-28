/**
 * The real system end to end: browser → built React app → Node API → PostgreSQL.
 * Nothing is mocked; the deadline worker runs as in production.
 */
import { expect, test, type Page } from '@playwright/test';
import { act, db, dialog, registerCase, runWorkerOnce, signIn, toast } from './support';

test.describe.configure({ mode: 'serial' });

const consoleErrors: string[] = [];
function watchConsole(page: Page) {
  page.on('pageerror', (e) => consoleErrors.push(`pageerror: ${e.message}`));
  page.on('console', (m) => {
    // Google Fonts may be unreachable in CI sandboxes; that is not an application error.
    if (m.type() === 'error' && !/fonts\.g|ERR_CERT|net::ERR_/.test(m.text()) && !/status of 4\d\d/.test(m.text())) consoleErrors.push(m.text());
  });
}

test.afterAll(async () => {
  await db.end();
});

test('login → dashboard → create → assign → production → QC fail → rework → QC pass → ready → payment → delivery → completed', async ({ page }) => {
  watchConsole(page);

  // Login and a dashboard computed by the API.
  await signIn(page, 'sagal@48hrs.lab');
  for (const kpi of ['Active cases', 'New cases', 'Due today', 'At risk', 'Overdue', 'Completed']) await expect(page.getByText(kpi, { exact: true }).first()).toBeVisible();

  // Create: the 48-hour clock starts on the server.
  const c = await registerCase(page, { patient: 'Journey Patient' });
  await expect(page.getByText('ON TRACK').first()).toBeVisible();
  const { rows: [stored] } = await db.query('SELECT "receivedAt", "dueAt" FROM cases WHERE id = $1', [c.id]);
  expect(stored.dueAt.getTime() - stored.receivedAt.getTime()).toBe(48 * 3_600_000);

  // Assign from the cases list row menu.
  await signIn(page, 'omar@48hrs.lab');
  await page.goto(`/cases?search=${encodeURIComponent(c.caseNumber)}`);
  await page.locator(`tbody tr button[aria-label="Actions for ${c.caseNumber}"]`).click();
  await page.getByRole('menuitem', { name: 'Assign technician' }).click();
  await dialog(page).getByRole('radio', { name: /Fatima Nur/ }).click();
  await dialog(page).getByRole('button', { name: 'Assign technician' }).click();
  await toast(page, 'Assign technician —');

  // Production → QC.
  await signIn(page, 'fatima@48hrs.lab');
  await page.goto(c.url);
  await act(page, 'Start production');
  await toast(page, 'Start production —');
  await act(page, 'Submit for QC');
  await dialog(page).getByRole('button', { name: 'Submit for QC' }).click();
  await toast(page, 'Submit for QC —');

  // QC fails with an issue and a note.
  await signIn(page, 'idil@48hrs.lab');
  await page.goto(c.url);
  await act(page, 'Review QC');
  await dialog(page).getByRole('button', { name: 'Fail — send back for rework' }).click();
  await expect(dialog(page).getByText('Select at least one issue.')).toBeVisible(); // validated before anything is sent
  await dialog(page).getByText('Contacts').click();
  await dialog(page).locator('textarea').fill('Open contact on 4 — rebuild.');
  await dialog(page).getByRole('button', { name: 'Fail — send back for rework' }).click();
  await toast(page, 'Fail QC —');
  await expect(page.getByText('REWORK REQUIRED').first()).toBeVisible();

  // Rework → QC → pass.
  await signIn(page, 'fatima@48hrs.lab');
  await page.goto(c.url);
  await act(page, 'Start rework');
  await toast(page, 'Start rework —');
  await act(page, 'Submit for QC');
  await dialog(page).getByRole('button', { name: 'Submit for QC' }).click();
  await toast(page, 'Submit for QC —');
  await signIn(page, 'idil@48hrs.lab');
  await page.goto(c.url);
  await act(page, 'Review QC');
  await page.getByRole('button', { name: 'Pass QC' }).click();
  await toast(page, 'Pass QC —');

  // Payment: an invalid amount is refused, then partial, then the rest.
  await signIn(page, 'sagal@48hrs.lab');
  await page.goto(c.url);
  await page.getByRole('button', { name: 'Record payment' }).click();
  await dialog(page).getByLabel('Amount').fill('999');
  await dialog(page).getByRole('button', { name: 'Record payment' }).click();
  await expect(dialog(page).getByText(/cannot exceed the remaining balance/)).toBeVisible();
  const refused = await page.request.post('/api/payments', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: { invoiceId: (await db.query('SELECT id FROM invoices WHERE "caseId" = $1', [c.id])).rows[0].id, amount: 999, method: 'cash' } });
  expect(refused.status()).toBe(422);
  expect((await refused.json()).errors.amount[0]).toMatch(/cannot exceed/);
  await dialog(page).getByLabel('Amount').fill('10');
  await dialog(page).getByRole('button', { name: 'Record payment' }).click();
  await toast(page, 'recorded on');
  await expect(page.getByText('Partial').first()).toBeVisible();
  await page.getByRole('button', { name: 'Record payment' }).click();
  await dialog(page).getByLabel('Method').selectOption('mobile_money');
  await dialog(page).getByLabel('Reference').fill('MM-7788');
  await dialog(page).getByRole('button', { name: 'Record payment' }).click();
  await toast(page, 'recorded on');
  await expect(page.getByText('Paid', { exact: true }).first()).toBeVisible();
  const { rows: [inv] } = await db.query('SELECT total, "amountPaid" FROM invoices WHERE "caseId" = $1', [c.id]);
  expect(Number(inv.amountPaid)).toBe(Number(inv.total));

  // Delivery: dispatch, then hand-over with who received it.
  await signIn(page, 'bashir@48hrs.lab');
  await page.goto(c.url);
  await act(page, 'Dispatch');
  await dialog(page).getByPlaceholder('Bashir Omar').fill('Bashir Omar');
  await dialog(page).getByRole('button', { name: 'Dispatch' }).click();
  await toast(page, 'Dispatch —');
  await act(page, 'Mark delivered');
  await dialog(page).getByPlaceholder('Name of the person who signed').fill('Dr. Amina Yusuf');
  await dialog(page).locator('textarea').fill('Signed at reception');
  await dialog(page).getByRole('button', { name: 'Mark delivered' }).click();
  await toast(page, 'Mark delivered —');
  await expect(page.getByText('DELIVERED WITHIN SLA').first()).toBeVisible();

  // The clinic confirms: completed, with the whole history stored.
  await signIn(page, 'amina@smiledental.so');
  await page.goto(c.url);
  await act(page, 'Confirm received');
  await toast(page, 'Confirm received —');
  await expect(page.getByText('COMPLETED').first()).toBeVisible();
  const { rows: history } = await db.query('SELECT "toStatus" FROM case_status_history WHERE "caseId" = $1 ORDER BY "createdAt"', [c.id]);
  expect(history.map((h) => h.toStatus)).toEqual(['received', 'assigned', 'in_production', 'quality_control', 'rework', 'in_production', 'quality_control', 'ready', 'out_for_delivery', 'delivered', 'completed']);
  const { rows: [delivery] } = await db.query('SELECT d."receivedBy", d."deliveredAt", u.name FROM deliveries d JOIN users u ON u.id = d."recordedById" WHERE d."caseId" = $1', [c.id]);
  expect(delivery).toMatchObject({ receivedBy: 'Dr. Amina Yusuf', name: 'Bashir Omar' });
  expect(delivery.deliveredAt).toBeTruthy();

  expect(consoleErrors).toEqual([]);
});

test('deadline exceeded → overdue, from the server clock and the deadline worker', async ({ page }) => {
  await signIn(page, 'sagal@48hrs.lab');
  const c = await registerCase(page, { clinic: 'Banadir Dental Centre', doctor: 'Dr. Abdirahman Ali', patient: 'Overdue Patient', teeth: [19] });
  await expect(page.getByText('ON TRACK').first()).toBeVisible();

  // 49 hours pass (the deadline is stored data, so time is moved in the database).
  await db.query(`UPDATE cases SET "receivedAt" = now() - interval '49 hours', "dueAt" = now() - interval '1 hour' WHERE id = $1`, [c.id]);
  runWorkerOnce();

  await signIn(page, 'omar@48hrs.lab');
  await page.goto(c.url);
  await expect(page.getByText(/^OVERDUE$/i).first()).toBeVisible();
  await expect(page.getByText(/Overdue by 1h/).first()).toBeVisible();
  await page.goto(`/cases?sla=overdue&search=${encodeURIComponent(c.caseNumber)}`);
  await expect(page.locator('tbody tr', { hasText: c.caseNumber })).toBeVisible();
  await page.getByRole('button', { name: /Notifications/ }).click();
  await expect(page.getByText('Case overdue').first()).toBeVisible();

  // A second worker pass does not notify again.
  const count = async () => Number((await db.query(`SELECT count(*) FROM notifications WHERE "caseId" = $1 AND type = 'case_overdue'`, [c.id])).rows[0].count);
  const first = await count();
  runWorkerOnce();
  expect(await count()).toBe(first);
});

test('a wrong device clock cannot fake the SLA or end the session', async ({ page }) => {
  await page.clock.install({ time: new Date(Date.now() + 3 * 24 * 3_600_000) }); // device three days fast
  await signIn(page, 'omar@48hrs.lab');
  const { rows: [c] } = await db.query(`SELECT id FROM cases WHERE status = 'received' AND "dueAt" > now() + interval '20 hours' ORDER BY "dueAt" DESC LIMIT 1`);
  await page.goto(`/cases/${c.id}`);
  await expect(page.getByText('ON TRACK').first()).toBeVisible();
  await page.reload();
  await expect(page.getByText('ON TRACK').first()).toBeVisible();
  await expect(page).toHaveURL(new RegExp(`/cases/${c.id}$`)); // still signed in
});

test('a user without permission is blocked in the UI and by the API', async ({ page }) => {
  await signIn(page, 'fatima@48hrs.lab');
  for (const path of ['/users', '/roles', '/settings', '/invoices', '/reports', '/patients', '/cases/new']) {
    await page.goto(path);
    await expect(page.getByText(/You do not have access to this page|Access restricted/).first()).toBeVisible();
  }
  const headers = { 'X-Requested-With': 'XMLHttpRequest' };
  for (const [method, path] of [['get', '/api/users'], ['get', '/api/invoices'], ['post', '/api/payments'], ['post', '/api/patients'], ['get', '/api/reports/cases?from=2026-01-01&to=2026-01-31']] as const) {
    const res = method === 'get' ? await page.request.get(path) : await page.request.post(path, { headers, data: {} });
    expect(res.status(), path).toBe(403);
    expect(await res.json()).toEqual({ message: 'Access restricted.' });
  }
  const { rows: [other] } = await db.query(`SELECT id FROM cases WHERE "technicianId" <> 'tec_fatima' AND status = 'in_production' LIMIT 1`);
  expect((await page.request.get(`/api/cases/${other.id}`)).status()).toBe(404);
});

test('an invalid workflow transition is refused (409) and the page shows the real state', async ({ page, browser }) => {
  const { rows: [c] } = await db.query(`SELECT id FROM cases WHERE status = 'received' ORDER BY "createdAt" DESC LIMIT 1`);
  await signIn(page, 'omar@48hrs.lab');
  await page.goto(`/cases/${c.id}`);
  await expect(page.getByRole('button', { name: 'Start review', exact: true })).toBeVisible();

  // Meanwhile an admin moves the case in another browser.
  const other = await (await browser.newContext()).newPage();
  await signIn(other, 'hodan@48hrs.lab');
  const moved = await other.request.post(`/api/cases/${c.id}/status`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: { status: 'review' } });
  expect(moved.status()).toBe(200);
  const again = await other.request.post(`/api/cases/${c.id}/status`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: { status: 'review' } });
  expect(again.status()).toBe(409);
  expect(await again.json()).toMatchObject({ message: 'Invalid workflow transition.', currentStatus: 'review' });

  await act(page, 'Start review');
  await toast(page, /Invalid workflow transition\. The case is currently "In review"/);
  await expect(page.getByRole('button', { name: 'Start review', exact: true })).toHaveCount(0);
  await expect(page.getByText('IN REVIEW').first()).toBeVisible();
});

test('session expiration: silent refresh, then sign-out and return after the session ends', async ({ page, context }) => {
  await signIn(page, 'sagal@48hrs.lab');
  await page.goto('/cases');

  // The short-lived access token is gone: the app refreshes it and carries on.
  await context.clearCookies({ name: 'lab_access' });
  await page.goto('/patients');
  await expect(page.getByRole('heading', { name: 'Patients' })).toBeVisible();
  expect((await context.cookies()).some((k) => k.name === 'lab_access')).toBe(true);

  // The session itself ends (8 hours, or revoked): the user is signed out and sent back after signing in.
  await db.query(`UPDATE sessions SET "expiresAt" = now() - interval '1 second' WHERE "userId" = 'usr_sagal'`);
  await page.goto('/payments');
  await page.waitForURL(/\/login\?next=%2Fpayments/);
  await expect(page.getByText(/Your session (has )?expired/i).first()).toBeVisible();
  await page.fill('input[type=email]', 'sagal@48hrs.lab');
  await page.fill('input[autocomplete=current-password]', process.env.E2E_PASSWORD!);
  await page.click('button[type=submit]');
  await page.waitForURL(/\/payments$/);
});

test('global search finds cases and patients from PostgreSQL, within the user\'s scope', async ({ page }) => {
  watchConsole(page);
  await signIn(page, 'sagal@48hrs.lab');
  const patient = `Searchable Warsame ${Date.now().toString(36)}`;
  const c = await registerCase(page, { patient });

  const search = async (term: string) => {
    await page.getByRole('button', { name: 'Search cases, patients, doctors, clinics and invoices' }).click();
    await page.getByPlaceholder('Case ID, patient, doctor, clinic, phone or invoice…').fill(term);
  };
  const results = () => page.getByRole('dialog', { name: 'Global search' });

  await search(c.caseNumber);
  await results().getByRole('group', { name: 'Cases' }).getByRole('option', { name: new RegExp(c.caseNumber) }).click();
  await page.waitForURL(`**/cases/${c.id}`);

  await search(patient);
  await expect(results().getByRole('group', { name: 'Cases' }).getByRole('option', { name: new RegExp(c.caseNumber) })).toBeVisible();
  await results().getByRole('group', { name: 'Patients' }).getByRole('option', { name: new RegExp(patient) }).click();
  await page.waitForURL(/\/patients\/[\w-]+$/);

  await search('zzqx-no-such-thing');
  await expect(results().getByText('No results for “zzqx-no-such-thing”.')).toBeVisible();
  await page.keyboard.press('Escape');

  // A clinic user never finds another clinic's case, even by its exact number.
  const { rows: [foreign] } = await db.query(`SELECT "caseNumber" FROM cases WHERE "clinicId" <> 'cln_smile' ORDER BY "createdAt" DESC LIMIT 1`);
  await signIn(page, 'amina@smiledental.so');
  await search(foreign.caseNumber);
  await expect(results().getByText(`No results for “${foreign.caseNumber}”.`)).toBeVisible();
  expect(consoleErrors).toEqual([]);
});
