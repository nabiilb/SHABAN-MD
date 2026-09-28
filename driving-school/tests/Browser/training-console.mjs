/*
 * Drives the instructor training console in a real browser.
 *
 * The bug this was written for is invisible to the server: the page renders,
 * the button draws, and Alpine — which only exists in the browser — never
 * initialises, so "+ Add Student" does nothing. AlpineAttributeIntegrityTest
 * guards the cause in CI; this walks the whole feature the way a teacher does.
 *
 *   php artisan serve --port=8010
 *   node tests/Browser/training-console.mjs [width] [email] [password]
 *
 * Exits non-zero if any step fails or the console reports an error.
 */
import { chromium } from 'playwright-core';

const width = Number(process.argv[2] || 375);
const email = process.argv[3] || 'xasan@example.com';
const password = process.argv[4] || 'password';
const base = process.env.APP_URL_TEST || 'http://127.0.0.1:8010';

const executablePath = process.env.CHROMIUM_PATH
    || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const browser = await chromium.launch({ executablePath });
const page = await browser.newPage({ viewport: { width, height: 812 } });

const errors = [];
page.on('pageerror', e => errors.push(e.message));
page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

const checks = {};
const check = (name, value) => { checks[name] = value; };

await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
await page.fill('input[name=email]', email);
await page.fill('input[name=password]', password);
await page.click('button[type=submit]');
await page.waitForLoadState('networkidle');

await page.goto(`${base}/instructor/training`, { waitUntil: 'networkidle' });
await page.waitForTimeout(500);

await page.locator('button:has-text("Add Student")').first().click();
await page.waitForTimeout(400);
check('the modal opens', await page.locator('#student-search').isVisible());

const term = process.env.SEARCH_TERM || 'Nasteexo';

await page.fill('#student-search', term);
await page.waitForTimeout(1200);

// Scoped to a row that actually matched, rather than any list item on the page.
const card = page.locator('li', { has: page.locator(`button:has-text("${term}")`) }).first();
const text = () => card.textContent();
const remaining = async () => (await text()).match(/Remaining:\s*(\d+)/)?.[1] ?? null;

check('a student is found', await card.isVisible());

const before = await remaining();
await card.locator('button:has-text("Edit remaining days")').click();
await page.waitForTimeout(300);

const input = card.locator('input[type=number]');
check('the editor opens', await input.isVisible());
check('it is prefilled with the current figure', (await input.inputValue()) === before);

await input.fill(String(Math.max(0, Number(before) - 1)));
await card.locator('button:has-text("Save")').click();
await page.waitForTimeout(1200);

check('the card updates without a reload', page.url().endsWith('/instructor/training'));
check('the new figure is shown', (await remaining()) === String(Math.max(0, Number(before) - 1)));
check('nothing overflows sideways', ! await page.evaluate(
    () => document.documentElement.scrollWidth > window.innerWidth,
));
check('the console is clean', errors.length === 0);

const failed = Object.entries(checks).filter(([, ok]) => ! ok);

for (const [name, ok] of Object.entries(checks)) {
    console.log(`${ok ? '  ok  ' : ' FAIL '} ${name}`);
}

if (errors.length) {
    console.log('\nConsole errors:');
    errors.slice(0, 10).forEach(e => console.log('  ' + e));
}

await browser.close();
process.exit(failed.length === 0 && errors.length === 0 ? 0 : 1);
