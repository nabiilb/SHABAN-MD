// Builds the web app in Node-API mode for the e2e run (dist-e2e), whatever the local .env says.
import { spawnSync } from 'node:child_process';

const r = spawnSync('npx', ['vite', 'build', '--outDir', 'dist-e2e', '--emptyOutDir', '--logLevel', 'warn'], {
  stdio: 'inherit',
  env: { ...process.env, VITE_USE_MOCKS: 'false', VITE_API_URL: '/api' },
});
process.exit(r.status ?? 1);
