/**
 * The worker as a real process (what cron/systemd runs): `--once` processes
 * the pending work and exits 0, exits 0 when there is nothing to do, and exits
 * non-zero when a pass fails; without `--once` it keeps running until SIGTERM.
 * Child processes run on the real clock, so the cases used here have deadlines
 * in the distant past and are overdue whatever today's date is.
 */
import { spawn } from 'node:child_process';
import { beforeEach, describe, expect, it } from 'vitest';
import { prisma, resetDb } from './helpers.ts';

beforeEach(() => resetDb());

interface Run { code: number | null; out: string; alive: boolean }

function worker(args: string[], opts: { env?: Record<string, string>; stopAfterMs?: number } = {}): Promise<Run> {
  return new Promise((resolve) => {
    // NODE_ENV=development: test mode silences the logs these assertions read.
    const child = spawn(process.execPath, ['--import', 'tsx', 'src/jobs/worker.ts', ...args], { cwd: new URL('..', import.meta.url).pathname, env: { ...process.env, NODE_ENV: 'development', LOG_LEVEL: 'info', ...opts.env } });
    let out = '';
    let alive = false;
    child.stdout.on('data', (d) => (out += d));
    child.stderr.on('data', (d) => (out += d));
    if (opts.stopAfterMs) {
      setTimeout(() => {
        alive = child.exitCode === null;
        child.kill('SIGTERM');
      }, opts.stopAfterMs);
    }
    child.on('exit', (code) => resolve({ code, out, alive }));
  });
}

async function makeOverdue() {
  const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production' } });
  return prisma.dentalCase.update({ where: { id: c.id }, data: { receivedAt: new Date('2001-01-01T08:00:00Z'), dueAt: new Date('2001-01-03T08:00:00Z'), atRiskNotifiedAt: null, overdueNotifiedAt: null } });
}
const settleEverything = () => prisma.dentalCase.updateMany({ data: { atRiskNotifiedAt: new Date(), overdueNotifiedAt: new Date() } });

describe('worker --once', () => {
  it('no pending work: exits 0 and reports nothing done', async () => {
    await settleEverything();
    const before = await prisma.notification.count();
    const run = await worker(['--once']);
    expect(run.code, run.out).toBe(0);
    expect(run.out).toMatch(/"checked":0/);
    expect(await prisma.notification.count()).toBe(before);
  });

  it('pending work: processes it once and exits 0; a second run does not repeat it', async () => {
    await settleEverything();
    const c = await makeOverdue();
    const run = await worker(['--once']);
    expect(run.code, run.out).toBe(0);
    expect(run.out).toMatch(/"overdue":1/);
    const row = await prisma.dentalCase.findUniqueOrThrow({ where: { id: c.id } });
    expect(row.overdueNotifiedAt).not.toBeNull();
    const sent = await prisma.notification.count({ where: { caseId: c.id, type: 'case_overdue' } });
    expect(sent).toBeGreaterThan(0);

    const again = await worker(['--once']);
    expect(again.code).toBe(0);
    expect(await prisma.notification.count({ where: { caseId: c.id, type: 'case_overdue' } })).toBe(sent);
  });

  it('two workers started together send each alert exactly once', async () => {
    await settleEverything();
    const c = await makeOverdue();
    const [a, b] = await Promise.all([worker(['--once']), worker(['--once'])]);
    expect([a.code, b.code]).toEqual([0, 0]);
    const recipients = await prisma.notification.findMany({ where: { caseId: c.id, type: 'case_overdue' }, select: { userId: true } });
    expect(recipients.length).toBeGreaterThan(0);
    expect(new Set(recipients.map((r) => r.userId)).size).toBe(recipients.length); // nobody notified twice
  });

  it('a failed pass exits non-zero, so cron and monitoring see it', async () => {
    const run = await worker(['--once'], { env: { DATABASE_URL: 'postgresql://lab:wrong@127.0.0.1:5432/no_such_database' } });
    expect(run.code, run.out).not.toBe(0);
    expect(run.out).toMatch(/worker pass failed/);
  });
});

describe('continuous worker', () => {
  it('keeps running between passes and stops cleanly on SIGTERM', async () => {
    await settleEverything();
    const run = await worker([], { stopAfterMs: 4_000 });
    expect(run.alive, run.out).toBe(true);
    expect(run.code).toBe(0);
    expect(run.out).toMatch(/"once":false/);
    expect(run.out).not.toMatch(/worker pass failed/);
  });
});
