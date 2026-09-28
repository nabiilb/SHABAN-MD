import { createApp } from './app.ts';
import { env } from './config/env.ts';
import { logger } from './lib/logger.ts';
import { disconnect } from './lib/prisma.ts';
import { ensureStorage } from './lib/storage.ts';

await ensureStorage();
const server = createApp().listen(env.PORT, () => logger.info({ port: env.PORT, env: env.NODE_ENV }, '48HRS API listening'));

let closing = false;
async function shutdown(signal: string) {
  if (closing) return;
  closing = true;
  logger.info({ signal }, 'shutting down');
  server.close(async () => {
    await disconnect();
    process.exit(0);
  });
  // Stop waiting for idle keep-alive connections after 10 s.
  setTimeout(() => process.exit(1), 10_000).unref();
}

process.on('SIGTERM', () => void shutdown('SIGTERM'));
process.on('SIGINT', () => void shutdown('SIGINT'));
