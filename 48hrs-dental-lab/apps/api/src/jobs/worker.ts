/**
 * Background worker — run next to the API (npm run worker).
 *
 * Every WORKER_INTERVAL_SECONDS it scans for at-risk and overdue cases and
 * raises their notifications (once per case, safe with several workers), and
 * once an hour purges expired sessions. `--once` runs a single pass and exits,
 * for cron-style scheduling.
 */
import { env } from '../config/env.ts';
import { logger } from '../lib/logger.ts';
import { disconnect } from '../lib/prisma.ts';
import { purgeExpiredCredentials, runDeadlineScan } from './deadline-scan.ts';

const once = process.argv.includes('--once');
const HOUR = 3_600_000;
let lastPurge = 0;
let stopping = false;
let timer: NodeJS.Timeout | undefined;

async function tick() {
  try {
    const scan = await runDeadlineScan();
    if (scan.atRisk || scan.overdue) logger.info({ scan }, 'deadline alerts raised');
    else logger.debug({ scan }, 'deadline scan');
    if (Date.now() - lastPurge > HOUR) {
      lastPurge = Date.now();
      const purged = await purgeExpiredCredentials();
      if (purged.sessions || purged.resetTokens) logger.info({ purged }, 'expired credentials purged');
    }
  } catch (err) {
    logger.error({ err }, 'worker pass failed');
  }
}

async function loop() {
  await tick();
  if (once || stopping) return shutdown();
  timer = setTimeout(() => void loop(), env.WORKER_INTERVAL_SECONDS * 1000);
}

async function shutdown() {
  stopping = true;
  clearTimeout(timer);
  await disconnect();
  process.exit(0);
}

process.on('SIGTERM', () => void shutdown());
process.on('SIGINT', () => void shutdown());

logger.info({ intervalSeconds: env.WORKER_INTERVAL_SECONDS, once }, '48HRS worker started');
void loop();
