import { defineConfig } from '@playwright/test';
import { BASE_URL, initStackEnv, stackEnv } from './e2e/stack-env';

initStackEnv();

/**
 * Full-stack end-to-end tests: the production web build, Laravel and MySQL,
 * served by Apache with the Hostinger layout (public_html/48hrs_lab + .htaccess
 * + laravel.php). No Node backend, no mocks. Run with `npm run e2e`.
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
    command: 'node e2e/serve-apache.mjs',
    url: `${BASE_URL}/api/health`,
    reuseExistingServer: false,
    timeout: 60_000,
    env: { E2E_PORT: new URL(BASE_URL).port, E2E_LARAVEL_ENV: JSON.stringify(stackEnv()) },
  },
});
