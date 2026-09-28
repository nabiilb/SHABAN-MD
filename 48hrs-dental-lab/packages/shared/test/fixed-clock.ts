/**
 * Test clock (vitest setupFiles in every workspace): tests never depend on the
 * machine's wall-clock date, time of day or time zone. The clock starts at a
 * fixed instant and advances in real time from there, so ordering, TTLs and
 * "now" still behave naturally. Tests may move it with vi.setSystemTime /
 * vi.setSystemTime (vi.useFakeTimers is a no-op while the clock is faked: call
 * vi.useRealTimers() first for a frozen clock); after vi.useRealTimers the test
 * clock is re-pinned, continuing forward.
 *
 * Default: Monday 2026-06-15 12:00 in Africa/Mogadishu (the tests' LAB_TIMEZONE).
 * Override to prove determinism, e.g. one minute before lab midnight:
 *   TEST_EPOCH=2026-06-15T20:59:00Z npm test
 */
import { afterEach, beforeAll, beforeEach, vi } from 'vitest';

const epoch = Date.parse(process.env.TEST_EPOCH ?? '2026-06-15T09:00:00Z');
if (Number.isNaN(epoch)) throw new Error(`TEST_EPOCH is not a valid ISO timestamp: ${process.env.TEST_EPOCH}`);
const realStart = performance.now();

function pin() {
  if (vi.isFakeTimers()) return;
  vi.useFakeTimers({ toFake: ['Date'], now: epoch + (performance.now() - realStart), shouldAdvanceTime: true });
}

pin(); // before the test module loads: module-level Date.now() is pinned too
beforeAll(pin);
beforeEach(pin);
// After hooks run in reverse order, so this follows a test file's own afterEach(vi.useRealTimers):
// a describe's beforeAll that comes next (e.g. seeding) still sees the test clock.
afterEach(pin);
