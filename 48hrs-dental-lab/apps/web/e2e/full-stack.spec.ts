/**
 * The real system end to end: browser → built React app → Node API → PostgreSQL.
 * Nothing is mocked; the deadline worker runs as in production.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, request, test, type Page } from '@playwright/test';
import { BASE_URL } from './stack-env';
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

test('client portal → STL upload → accept with deposit → assign → production → QC fail → rework → QC pass → ready → delivery → final payment → completed', async ({ page }) => {
  watchConsole(page);

  // The clinic submits through the portal, with an STL scan. No SLA clock yet.
  await signIn(page, 'amina@smiledental.so');
  for (const kpi of ['Active cases', 'Completed']) await expect(page.getByText(kpi, { exact: true }).first()).toBeVisible();
  await page.goto('/cases/new');
  await page.getByRole('combobox').first().click();
  await page.locator('[cmdk-item]', { hasText: 'Dr. Yasin Warsame' }).click();
  await page.getByRole('radio', { name: 'New patient' }).click();
  await page.getByPlaceholder('Ahmed Mohamed').fill('Journey Patient');
  await page.getByRole('button', { name: 'Submit case' }).first().click();
  await expect(page.getByText('Select at least one tooth on the chart before submitting.').first()).toBeVisible(); // nothing sent yet
  await page.getByRole('button', { name: 'Tooth 14', exact: true }).click();
  await page.getByRole('button', { name: 'Tooth 15', exact: true }).click();
  const stl = Buffer.from('solid upper\n facet normal 0 0 1\n  outer loop\n   vertex 0 0 0\n   vertex 1 0 0\n   vertex 0 1 0\n  endloop\n endfacet\nendsolid upper\n');
  await page.locator('input[type=file]').setInputFiles({ name: 'upper-arch.stl', mimeType: 'model/stl', buffer: stl });
  await page.getByRole('button', { name: 'Submit case' }).first().click();
  await page.waitForURL(/\/cases\/(?!new)[\w-]+$/);
  const c = { url: page.url(), id: page.url().split('/').pop()!, caseNumber: (await page.locator('header h1', { hasText: /^DL-/ }).innerText()).trim() };
  await expect(page.getByText('SLA NOT STARTED').first()).toBeVisible();
  await expect(page.getByText('upper-arch.stl').first()).toBeVisible();
  const { rows: [submitted] } = await db.query('SELECT status, "receivedAt", "clinicId" FROM cases WHERE id = $1', [c.id]);
  expect(submitted).toMatchObject({ status: 'submitted', receivedAt: null, clinicId: 'cln_smile' });
  const { rows: [file] } = await db.query('SELECT id, "storageKey", extension, size, "mimeType" FROM case_attachments WHERE "caseId" = $1', [c.id]);
  expect(file).toMatchObject({ extension: 'stl', size: stl.length, mimeType: 'model/stl' });
  expect(Buffer.compare(readFileSync(join(process.env.E2E_UPLOAD_DIR!, file.storageKey)), stl)).toBe(0); // database and disk agree
  const anonymous = await request.newContext({ baseURL: BASE_URL }); // no session cookies
  expect((await anonymous.get(`/api/cases/${c.id}/attachments/${file.id}/download`)).status()).toBe(401);
  expect((await anonymous.get(`/${file.storageKey}`)).headers()['content-type']).not.toMatch(/stl/); // storage is not public
  await anonymous.dispose();
  const own = await page.request.get(`/api/cases/${c.id}/attachments/${file.id}/download`);
  expect(own.status()).toBe(200);
  expect(Buffer.compare(await own.body(), stl)).toBe(0);

  // Reception accepts with a deposit: the 48-hour clock starts on the server.
  await signIn(page, 'sagal@48hrs.lab');
  await page.goto(c.url);
  await act(page, 'Accept case');
  await page.getByRole('radio', { name: 'Deposit' }).click();
  await page.getByLabel('Amount').fill('20');
  await page.getByRole('button', { name: 'Confirm acceptance' }).click();
  await toast(page, 'Accept case —');
  await expect(page.getByText('ON TRACK').first()).toBeVisible();
  await expect(page.getByText('Partial').first()).toBeVisible();
  const { rows: [stored] } = await db.query('SELECT "receivedAt", "dueAt" FROM cases WHERE id = $1', [c.id]);
  expect(stored.dueAt.getTime() - stored.receivedAt.getTime()).toBe(48 * 3_600_000);
  const { rows: [deposit] } = await db.query('SELECT i.total, i."amountPaid", count(p.id)::int AS n FROM invoices i JOIN payments p ON p."invoiceId" = i.id WHERE i."caseId" = $1 GROUP BY i.id', [c.id]);
  expect({ paid: Number(deposit.amountPaid), n: deposit.n }).toEqual({ paid: 20, n: 1 });

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
  await expect(page.getByRole('button', { name: 'Record payment' })).toHaveCount(0); // technicians never handle money
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
  await dialog(page).locator('textarea').fill('Open contact on 14 — rebuild.');
  await dialog(page).getByRole('button', { name: 'Fail — send back for rework' }).click();
  await toast(page, 'Fail QC —');
  await expect(page.getByText('REWORK REQUIRED').first()).toBeVisible();

  // Rework → QC → pass → ready.
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
  await expect.poll(async () => (await db.query('SELECT status FROM cases WHERE id = $1', [c.id])).rows[0].status).toBe('ready');

  // Delivery: dispatch, then the hand-over is confirmed with who received it.
  await signIn(page, 'bashir@48hrs.lab');
  await page.goto(c.url);
  await act(page, 'Dispatch');
  await dialog(page).getByPlaceholder('Bashir Omar').fill('Bashir Omar');
  await dialog(page).getByRole('button', { name: 'Dispatch' }).click();
  await toast(page, 'Dispatch —');
  await act(page, 'Mark delivered');
  await dialog(page).getByPlaceholder('Name of the person who signed').fill('Dr. Yasin Warsame');
  await dialog(page).locator('textarea').fill('Signed at reception');
  await dialog(page).getByRole('button', { name: 'Mark delivered' }).click();
  await toast(page, 'Mark delivered —');
  await expect(page.getByText('DELIVERED WITHIN SLA').first()).toBeVisible();

  // Final payment: an invalid amount is refused by the form and by the API, then the balance is settled.
  await signIn(page, 'sagal@48hrs.lab');
  await page.goto(c.url);
  await page.getByRole('button', { name: 'Record payment' }).click();
  await dialog(page).getByLabel('Amount').fill('9999');
  await dialog(page).getByRole('button', { name: 'Record payment' }).click();
  await expect(dialog(page).getByText(/cannot exceed the remaining balance/)).toBeVisible();
  const invoiceId = (await db.query('SELECT id FROM invoices WHERE "caseId" = $1', [c.id])).rows[0].id;
  const refused = await page.request.post('/api/payments', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: { invoiceId, amount: 9999, method: 'cash' } });
  expect(refused.status()).toBe(422);
  expect((await refused.json()).errors.amount[0]).toMatch(/cannot exceed/);
  await dialog(page).getByLabel('Method').selectOption('mobile_money');
  await dialog(page).getByLabel('Amount').fill('');
  await dialog(page).getByRole('button', { name: 'Record payment' }).click(); // reference required for mobile money
  await expect(dialog(page).getByText(/Enter the transaction reference|Enter an amount/).first()).toBeVisible();
  await dialog(page).getByLabel('Method').selectOption('cash');
  const { rows: [due] } = await db.query('SELECT (total - "amountPaid")::float AS remaining FROM invoices WHERE id = $1', [invoiceId]);
  await dialog(page).getByLabel('Amount').fill(String(due.remaining));
  await dialog(page).getByRole('button', { name: 'Record payment' }).click();
  await toast(page, 'recorded on');
  await expect(page.getByText('Paid', { exact: true }).first()).toBeVisible();
  const { rows: [inv] } = await db.query('SELECT total, "amountPaid", (SELECT sum(amount) FROM payments WHERE "invoiceId" = invoices.id) AS sum FROM invoices WHERE id = $1', [invoiceId]);
  expect(Number(inv.amountPaid)).toBe(Number(inv.total));
  expect(Number(inv.sum)).toBe(Number(inv.total));

  // The clinic confirms receipt: completed, with the whole history stored.
  await signIn(page, 'amina@smiledental.so');
  await page.goto(c.url);
  await act(page, 'Confirm received');
  await toast(page, 'Confirm received —');
  await expect(page.getByText('COMPLETED').first()).toBeVisible();
  const { rows: history } = await db.query('SELECT "toStatus" FROM case_status_history WHERE "caseId" = $1 ORDER BY "createdAt"', [c.id]);
  expect(history.map((h) => h.toStatus)).toEqual(['submitted', 'received', 'assigned', 'in_production', 'quality_control', 'rework', 'in_production', 'quality_control', 'ready', 'out_for_delivery', 'delivered', 'completed']);
  const { rows: [delivery] } = await db.query(`SELECT d."receivedBy", d."deliveredAt", u.name FROM deliveries d JOIN users u ON u.id = d."recordedById" WHERE d."caseId" = $1 AND d.status = 'delivered'`, [c.id]);
  expect(delivery).toMatchObject({ receivedBy: 'Dr. Yasin Warsame', name: 'Bashir Omar' });
  expect(delivery.deliveredAt).toBeTruthy();
  const { rows: [qc] } = await db.query(`SELECT count(*) FILTER (WHERE result = 'failed')::int AS failed, count(*) FILTER (WHERE result = 'passed')::int AS passed FROM quality_checks WHERE "caseId" = $1`, [c.id]);
  expect(qc).toEqual({ failed: 1, passed: 1 });

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

// The device clock is wrong by more than a whole session (8 h). Business time comes from the server.
for (const [label, skewHours] of [['ahead', 9], ['behind', -9]] as const) {
  test(`device clock ${label} by ${Math.abs(skewHours)} h: login, reload and navigation keep the session; SLA follows the server clock`, async ({ page }) => {
    // Two in-production cases with deadlines set by the server's clock: 15 h left (at risk starts at 12 h), and 1 h overdue.
    const { rows: [soon, late] } = await db.query(`SELECT id FROM cases WHERE status = 'in_production' ORDER BY id LIMIT 2 OFFSET ${skewHours > 0 ? 0 : 2}`);
    await db.query(`UPDATE cases SET "receivedAt" = now() - interval '33 hours', "dueAt" = now() + interval '15 hours', "atRiskNotifiedAt" = NULL WHERE id = $1`, [soon.id]);
    await db.query(`UPDATE cases SET "receivedAt" = now() - interval '49 hours', "dueAt" = now() - interval '1 hour' WHERE id = $1`, [late.id]);

    await page.clock.install({ time: new Date(Date.now() + skewHours * 3_600_000) });
    await signIn(page, 'omar@48hrs.lab'); // fresh login
    for (const reload of [false, true]) {
      await page.goto(`/cases/${soon.id}`);
      if (reload) await page.reload();
      // 15 h left on the server: a client trusting a clock 9 h fast would see 6 h left, "at risk".
      await expect(page.getByText('ON TRACK').first()).toBeVisible();
      await page.goto(`/cases/${late.id}`);
      // 1 h overdue on the server: a client 9 h slow would still count down.
      await expect(page.getByText(/^OVERDUE$/i).first()).toBeVisible();
      await expect(page.getByText(/Overdue by 1h/).first()).toBeVisible();
    }
    await page.goto('/cases');
    await expect(page).toHaveURL(/\/cases$/); // a valid server session is not ended by the device clock
    await expect(page.locator('tbody tr').first()).toBeVisible();

    // ...and an expired server session is not kept alive by it either.
    await db.query(`UPDATE sessions SET "expiresAt" = now() - interval '1 second' WHERE "userId" = 'usr_omar'`);
    await page.goto('/patients');
    await page.waitForURL(/\/login\?next=%2Fpatients/);
  });
}

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

test('stale page: another user moves the case first → 409, the page refetches and shows the server state', async ({ page, browser }) => {
  const { rows: [c] } = await db.query(`SELECT id, "caseNumber" FROM cases WHERE status = 'received' ORDER BY "createdAt" DESC LIMIT 1`);

  // Client B (lab manager) has the case open while it is "received".
  await signIn(page, 'omar@48hrs.lab');
  await page.goto(`/cases/${c.id}`);
  await expect(page.getByRole('button', { name: 'Start review', exact: true })).toBeVisible();

  // Client A (admin, another browser) starts the review through the UI.
  const other = await (await browser.newContext()).newPage();
  await signIn(other, 'hodan@48hrs.lab');
  await other.goto(`/cases/${c.id}`);
  await act(other, 'Start review');
  await toast(other, 'Start review —');
  await expect(other.getByText('In review', { exact: true }).first()).toBeVisible(); // the status badge (exact: not the toast)

  // B's page is now stale: its "Start review" is obsolete.
  const conflict = page.waitForResponse((r) => r.url().endsWith(`/api/cases/${c.id}/status`) && r.request().method() === 'POST');
  await act(page, 'Start review');
  const res = await conflict;
  expect(res.status()).toBe(409);
  expect(await res.json()).toMatchObject({ message: 'Invalid workflow transition.', currentStatus: 'review' });
  await toast(page, /Invalid workflow transition\. The case is currently "In review"/);
  // Refetched: the server's status is shown, the obsolete action is gone, nothing optimistic survived.
  await expect(page.getByText('In review', { exact: true }).first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Start review', exact: true })).toHaveCount(0);
  await page.reload();
  await expect(page.getByText('In review', { exact: true }).first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Start review', exact: true })).toHaveCount(0);
  const { rows } = await db.query(`SELECT count(*)::int AS n FROM case_status_history WHERE "caseId" = $1 AND "toStatus" = 'review'`, [c.id]);
  expect(rows[0].n).toBe(1); // only A's transition was stored

  // The API refuses the same obsolete request directly, too.
  const again = await page.request.post(`/api/cases/${c.id}/status`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: { status: 'review' } });
  expect(again.status()).toBe(409);
  await other.context().close();
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
