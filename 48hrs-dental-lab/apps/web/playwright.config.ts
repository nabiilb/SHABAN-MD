import { defineConfig } from '@playwright/test';
import { BASE_URL, initStackEnv, stackEnv } from './e2e/stack-env';

initStackEnv();

/**
 * Full-stack end-to-end tests: the built web app, the Node API and PostgreSQL,
 * exactly as deployed on a single origin. Run with `npm run e2e`.
 */
export default defineConfig({
  testDir: 'e2e',
  fullyParallel: false,
  workers: 1,
  timeout: 180_000,
  expect: { timeout: 10_000 },
  reporter: [['list']],
  globalSetup: './e2e/global-setup.ts',
  use: {
    baseURL: BASE_URL,
    viewport: { width: 1366, height: 900 },
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'npx tsx ../api/src/server.ts',
    url: `${BASE_URL}/api/health`,
    reuseExistingServer: false,
    timeout: 60_000,
    env: stackEnv(),
  },
});
