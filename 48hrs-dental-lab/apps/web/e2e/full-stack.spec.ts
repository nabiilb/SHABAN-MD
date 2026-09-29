/**
 * The real system end to end: browser → React production build → Apache
 * (Hostinger layout: public_html/48hrs_lab, .htaccess, laravel.php) → Laravel → MySQL.
 * Nothing is mocked; the deadline scan runs as the cron job runs it.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, request, test, type Page } from '@playwright/test';
import { app, ORIGIN } from './stack-env';
import { act, all, apiWrite, db, dialog, expireSessions, one, registerCase, runDeadlineScan, signIn, toast } from './support';

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
  await page.goto(app('/cases/new'));
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
  await page.waitForURL(/\/48hrs_lab\/cases\/(?!new)[\w-]+$/);
  const c = { url: page.url(), id: page.url().split('/').pop()!, caseNumber: (await page.locator('header h1', { hasText: /^DL-/ }).innerText()).trim() };
  await expect(page.getByText('SLA NOT STARTED').first()).toBeVisible();
  await expect(page.getByText('upper-arch.stl').first()).toBeVisible();
  const submitted = await one('SELECT status, received_at, clinic_id FROM cases WHERE id = ?', [c.id]);
  expect(submitted).toMatchObject({ status: 'submitted', received_at: null, clinic_id: 'cln_smile' });
  const file = await one<{ id: string; storage_key: string; extension: string; size: number; mime_type: string }>('SELECT id, storage_key, extension, size, mime_type FROM case_attachments WHERE case_id = ?', [c.id]);
  expect(file).toMatchObject({ extension: 'stl', size: stl.length, mime_type: 'model/stl' });
  expect(Buffer.compare(readFileSync(join(process.env.E2E_UPLOAD_DIR!, file.storage_key)), stl)).toBe(0); // database and disk agree
  const anonymous = await request.newContext({ baseURL: ORIGIN }); // no session cookies
  expect((await anonymous.get(app(`/api/cases/${c.id}/attachments/${file.id}/download`))).status()).toBe(401);
  for (const guess of [app(`/${file.storage_key}`), `/${file.storage_key}`, app(`/storage/${file.storage_key}`), app('/api/../laravel.php')]) {
    const res = await anonymous.get(guess);
    expect(res.headers()['content-type'] ?? '', guess).not.toMatch(/stl|octet/); // storage is not public
    expect((await res.body()).includes(stl)).toBe(false);
  }
  for (const secret of [app('/.htaccess'), app('/.user.ini'), app('/../48hrs_lab_app/.env')]) expect((await anonymous.get(secret)).status(), secret).toBeGreaterThanOrEqual(403);
  await anonymous.dispose();
  const own = await page.request.get(app(`/api/cases/${c.id}/attachments/${file.id}/download`));
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
  const stored = await one<{ received_at: Date; due_at: Date }>('SELECT received_at, due_at FROM cases WHERE id = ?', [c.id]);
  expect(stored.due_at.getTime() - stored.received_at.getTime()).toBe(48 * 3_600_000);
  const deposit = await one<{ amount_paid: string; n: number }>('SELECT i.amount_paid, count(p.id) AS n FROM invoices i JOIN payments p ON p.invoice_id = i.id WHERE i.case_id = ? GROUP BY i.id', [c.id]);
  expect({ paid: Number(deposit.amount_paid), n: Number(deposit.n) }).toEqual({ paid: 20, n: 1 });

  // Assign from the cases list row menu.
  await signIn(page, 'omar@48hrs.lab');
  await page.goto(app(`/cases?search=${encodeURIComponent(c.caseNumber)}`));
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
  await expect.poll(async () => (await one<{ status: string }>('SELECT status FROM cases WHERE id = ?', [c.id])).status).toBe('ready');

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
  const { id: invoiceId } = await one<{ id: string }>('SELECT id FROM invoices WHERE case_id = ?', [c.id]);
  const refused = await apiWrite(page, 'post', '/api/payments', { invoiceId, amount: 9999, method: 'cash' });
  expect(refused.status()).toBe(422);
  expect((await refused.json()).errors.amount[0]).toMatch(/cannot exceed/);
  await dialog(page).getByLabel('Method').selectOption('mobile_money');
  await dialog(page).getByLabel('Amount').fill('');
  await dialog(page).getByRole('button', { name: 'Record payment' }).click(); // reference required for mobile money
  await expect(dialog(page).getByText(/Enter the transaction reference|Enter an amount/).first()).toBeVisible();
  await dialog(page).getByLabel('Method').selectOption('cash');
  const due = await one<{ remaining: string }>('SELECT (total - amount_paid) AS remaining FROM invoices WHERE id = ?', [invoiceId]);
  await dialog(page).getByLabel('Amount').fill(String(Number(due.remaining)));
  await dialog(page).getByRole('button', { name: 'Record payment' }).click();
  await toast(page, 'recorded on');
  await expect(page.getByText('Paid', { exact: true }).first()).toBeVisible();
  const inv = await one<{ total: string; amount_paid: string; sum: string }>('SELECT total, amount_paid, (SELECT sum(amount) FROM payments WHERE invoice_id = invoices.id) AS sum FROM invoices WHERE id = ?', [invoiceId]);
  expect(Number(inv.amount_paid)).toBe(Number(inv.total));
  expect(Number(inv.sum)).toBe(Number(inv.total));

  // The clinic confirms receipt: completed, with the whole history stored.
  await signIn(page, 'amina@smiledental.so');
  await page.goto(c.url);
  await act(page, 'Confirm received');
  await toast(page, 'Confirm received —');
  await expect(page.getByText('COMPLETED').first()).toBeVisible();
  const history = await all<{ to_status: string }>('SELECT to_status FROM case_status_history WHERE case_id = ? ORDER BY created_at, id', [c.id]);
  expect(history.map((h) => h.to_status)).toEqual(['submitted', 'received', 'assigned', 'in_production', 'quality_control', 'rework', 'in_production', 'quality_control', 'ready', 'out_for_delivery', 'delivered', 'completed']);
  const delivery = await one<{ received_by: string; delivered_at: Date; name: string }>(`SELECT d.received_by, d.delivered_at, u.name FROM deliveries d JOIN users u ON u.id = d.recorded_by_id WHERE d.case_id = ? AND d.status = 'delivered'`, [c.id]);
  expect(delivery).toMatchObject({ received_by: 'Dr. Yasin Warsame', name: 'Bashir Omar' });
  expect(delivery.delivered_at).toBeTruthy();
  const qc = await one<{ failed: string; passed: string }>(`SELECT sum(result = 'failed') AS failed, sum(result = 'passed') AS passed FROM quality_checks WHERE case_id = ?`, [c.id]);
  expect({ failed: Number(qc.failed), passed: Number(qc.passed) }).toEqual({ failed: 1, passed: 1 });

  expect(consoleErrors).toEqual([]);
});

test('deadline exceeded → overdue, from the server clock and the scheduled deadline scan', async ({ page }) => {
  await signIn(page, 'sagal@48hrs.lab');
  const c = await registerCase(page, { clinic: 'Banadir Dental Centre', doctor: 'Dr. Abdirahman Ali', patient: 'Overdue Patient', teeth: [19] });
  await expect(page.getByText('ON TRACK').first()).toBeVisible();

  // 49 hours pass (the deadline is stored data, so time is moved in the database).
  await db.query('UPDATE cases SET received_at = UTC_TIMESTAMP(3) - INTERVAL 49 HOUR, due_at = UTC_TIMESTAMP(3) - INTERVAL 1 HOUR WHERE id = ?', [c.id]);
  runDeadlineScan();

  await signIn(page, 'omar@48hrs.lab');
  await page.goto(c.url);
  await expect(page.getByText(/^OVERDUE$/i).first()).toBeVisible();
  await expect(page.getByText(/Overdue by 1h/).first()).toBeVisible();
  await page.goto(app(`/cases?sla=overdue&search=${encodeURIComponent(c.caseNumber)}`));
  await expect(page.locator('tbody tr', { hasText: c.caseNumber })).toBeVisible();
  await page.getByRole('button', { name: /Notifications/ }).click();
  await expect(page.getByText('Case overdue').first()).toBeVisible();

  // A second scan does not notify again.
  const count = async () => Number((await one<{ n: number }>(`SELECT count(*) AS n FROM notifications WHERE case_id = ? AND type = 'case_overdue'`, [c.id])).n);
  const first = await count();
  expect(first).toBeGreaterThan(0);
  runDeadlineScan();
  expect(await count()).toBe(first);
});

// The device clock is wrong by more than a whole session (8 h). Business time comes from the server.
for (const [label, skewHours] of [['ahead', 9], ['behind', -9]] as const) {
  test(`device clock ${label} by ${Math.abs(skewHours)} h: login, reload and navigation keep the session; SLA follows the server clock`, async ({ page }) => {
    // Two in-production cases with deadlines set by the server's clock: 15 h left (at risk starts at 12 h), and 1 h overdue.
    const [soon, late] = await all<{ id: string }>(`SELECT id FROM cases WHERE status = 'in_production' ORDER BY id LIMIT 2 OFFSET ${skewHours > 0 ? 0 : 2}`);
    await db.query('UPDATE cases SET received_at = UTC_TIMESTAMP(3) - INTERVAL 33 HOUR, due_at = UTC_TIMESTAMP(3) + INTERVAL 15 HOUR, at_risk_notified_at = NULL WHERE id = ?', [soon.id]);
    await db.query('UPDATE cases SET received_at = UTC_TIMESTAMP(3) - INTERVAL 49 HOUR, due_at = UTC_TIMESTAMP(3) - INTERVAL 1 HOUR WHERE id = ?', [late.id]);

    await page.clock.install({ time: new Date(Date.now() + skewHours * 3_600_000) });
    await signIn(page, 'omar@48hrs.lab'); // fresh login
    for (const reload of [false, true]) {
      await page.goto(app(`/cases/${soon.id}`));
      if (reload) await page.reload();
      // 15 h left on the server: a client trusting a clock 9 h fast would see 6 h left, "at risk".
      await expect(page.getByText('ON TRACK').first()).toBeVisible();
      await page.goto(app(`/cases/${late.id}`));
      // 1 h overdue on the server: a client 9 h slow would still count down.
      await expect(page.getByText(/^OVERDUE$/i).first()).toBeVisible();
      await expect(page.getByText(/Overdue by 1h/).first()).toBeVisible();
    }
    await page.goto(app('/cases'));
    await expect(page).toHaveURL(/\/48hrs_lab\/cases$/); // a valid server session is not ended by the device clock
    await expect(page.locator('tbody tr').first()).toBeVisible();

    // ...and an expired server session is not kept alive by it either.
    expect(await expireSessions('usr_omar')).toBeGreaterThan(0);
    await page.goto(app('/patients'));
    await page.waitForURL(/\/login\?next=%2Fpatients/);
  });
}

test('a user without permission is blocked in the UI and by the API', async ({ page }) => {
  await signIn(page, 'fatima@48hrs.lab');
  for (const path of ['/users', '/roles', '/settings', '/invoices', '/reports', '/patients', '/cases/new']) {
    await page.goto(app(path));
    await expect(page.getByText(/You do not have access to this page|Access restricted/).first()).toBeVisible();
  }
  for (const [method, path] of [['get', '/api/users'], ['get', '/api/invoices'], ['post', '/api/payments'], ['post', '/api/patients'], ['get', '/api/reports/cases?from=2026-01-01&to=2026-01-31']] as const) {
    const res = method === 'get' ? await page.request.get(app(path)) : await apiWrite(page, 'post', path, {});
    expect(res.status(), path).toBe(403);
    expect(await res.json()).toEqual({ message: 'Access restricted.' });
  }
  const other = await one<{ id: string }>(`SELECT id FROM cases WHERE technician_id <> 'tec_fatima' AND status = 'in_production' LIMIT 1`);
  expect((await page.request.get(app(`/api/cases/${other.id}`))).status()).toBe(404);
});

test('stale page: another user moves the case first → 409, the page refetches and shows the server state', async ({ page, browser }) => {
  const c = await one<{ id: string }>(`SELECT id, case_number FROM cases WHERE status = 'received' ORDER BY created_at DESC LIMIT 1`);

  // Client B (lab manager) has the case open while it is "received".
  await signIn(page, 'omar@48hrs.lab');
  await page.goto(app(`/cases/${c.id}`));
  await expect(page.getByRole('button', { name: 'Start review', exact: true })).toBeVisible();

  // Client A (admin, another browser) starts the review through the UI.
  const other = await (await browser.newContext()).newPage();
  await signIn(other, 'hodan@48hrs.lab');
  await other.goto(app(`/cases/${c.id}`));
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
  const rows = await one<{ n: number }>(`SELECT count(*) AS n FROM case_status_history WHERE case_id = ? AND to_status = 'review'`, [c.id]);
  expect(Number(rows.n)).toBe(1); // only A's transition was stored

  // The API refuses the same obsolete request directly, too.
  const again = await apiWrite(page, 'post', `/api/cases/${c.id}/status`, { status: 'review' });
  expect(again.status()).toBe(409);
  await other.context().close();
});

test('CSRF and session expiry: a lost or stale token is renewed transparently; an ended session signs out and returns after sign-in', async ({ page, context }) => {
  await signIn(page, 'sagal@48hrs.lab');
  await page.goto(app('/cases'));

  // The CSRF cookie is gone (or stale): the next write fetches a fresh token and succeeds.
  await context.clearCookies({ name: 'XSRF-TOKEN' });
  await page.goto(app('/notifications'));
  await page.getByRole('button', { name: /Mark all (as )?read/i }).first().click();
  await expect.poll(async () => Number((await one<{ n: number }>(`SELECT count(*) AS n FROM notifications WHERE user_id = 'usr_sagal' AND read_at IS NULL`)).n)).toBe(0);
  expect((await context.cookies()).some((k) => k.name === 'XSRF-TOKEN')).toBe(true);
  // A write without the token is refused by the server (419), whatever the client does.
  const forged = await page.request.post(app('/api/notifications/read-all'), { headers: { Accept: 'application/json' } });
  expect(forged.status()).toBe(419);

  // The session itself ends (8 hours): the user is signed out and sent back after signing in.
  await expireSessions('usr_sagal');
  await page.goto(app('/payments'));
  await page.waitForURL(/\/login\?next=%2Fpayments/);
  await expect(page.getByText(/Your session (has )?(expired|ended)/i).first()).toBeVisible();
  await page.fill('input[type=email]', 'sagal@48hrs.lab');
  await page.fill('input[autocomplete=current-password]', process.env.E2E_PASSWORD!);
  await page.click('button[type=submit]');
  await page.waitForURL(/\/48hrs_lab\/payments$/);

  // The session cookie is scoped to the app folder, HTTP-only and SameSite=Lax.
  const session = (await context.cookies()).find((k) => k.httpOnly);
  expect(session).toMatchObject({ path: '/48hrs_lab/', httpOnly: true, sameSite: 'Lax' });
});

test('global search finds cases and patients from MySQL, within the user\'s scope', async ({ page }) => {
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
  await page.waitForURL(`**/48hrs_lab/cases/${c.id}`);

  await search(patient);
  await expect(results().getByRole('group', { name: 'Cases' }).getByRole('option', { name: new RegExp(c.caseNumber) })).toBeVisible();
  await results().getByRole('group', { name: 'Patients' }).getByRole('option', { name: new RegExp(patient) }).click();
  await page.waitForURL(/\/48hrs_lab\/patients\/[\w-]+$/);

  await search('zzqx-no-such-thing');
  await expect(results().getByText('No results for “zzqx-no-such-thing”.')).toBeVisible();
  await page.keyboard.press('Escape');

  // A clinic user never finds another clinic's case, even by its exact number.
  const foreign = await one<{ case_number: string }>(`SELECT case_number FROM cases WHERE clinic_id <> 'cln_smile' ORDER BY created_at DESC LIMIT 1`);
  await signIn(page, 'amina@smiledental.so');
  await search(foreign.case_number);
  await expect(results().getByText(`No results for “${foreign.case_number}”.`)).toBeVisible();
  expect(consoleErrors).toEqual([]);
});

test('deep links and reloads work under the sub-folder; the rest of the domain is untouched', async ({ page }) => {
  await signIn(page, 'omar@48hrs.lab');
  await page.goto(app('/reports'));
  await page.reload();
  await expect(page).toHaveURL(/\/48hrs_lab\/reports$/);
  await expect(page.getByRole('heading', { name: /Reports/ }).first()).toBeVisible();
  const home = await page.request.get('/');
  expect(await home.text()).toContain('sooryoscan.com'); // the domain's own page, not the app
  const asset = (await page.request.get(app('/'))).headers();
  expect(asset['cache-control']).toBe('no-cache');
  expect(asset['content-security-policy']).toMatch(/frame-ancestors 'none'/);
  const api = (await page.request.get(app('/api/health'))).headers();
  expect(api['content-security-policy']).toBe("default-src 'none'; frame-ancestors 'none'");
});
