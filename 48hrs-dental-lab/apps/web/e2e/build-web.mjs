// Builds the web app exactly as the Hostinger release does (sub-folder + Laravel API), into dist-e2e.
import { spawnSync } from 'node:child_process';

const r = spawnSync('npx', ['vite', 'build', '--outDir', 'dist-e2e', '--emptyOutDir', '--logLevel', 'warn'], {
  stdio: 'inherit',
  env: { ...process.env, VITE_USE_MOCKS: 'false', VITE_BASE_PATH: '/48hrs_lab/', VITE_API_URL: '/48hrs_lab/api' },
});
process.exit(r.status ?? 1);
