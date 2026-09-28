import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

/** Brings the test database to the latest migration before any suite runs. */
export default function setup() {
  const root = fileURLToPath(new URL('../../..', import.meta.url));
  execFileSync('npx', ['prisma', 'migrate', 'deploy'], {
    cwd: root,
    env: { ...process.env, DATABASE_URL: process.env.TEST_DATABASE_URL },
    stdio: 'pipe',
  });
}
