import { execFileSync } from 'node:child_process';
import { REPO_ROOT, stackEnv } from './stack-env';

/** Fresh database for the run: latest migrations + the demo dataset. */
export default function globalSetup() {
  const env = { ...process.env, ...stackEnv() };
  execFileSync('npx', ['prisma', 'migrate', 'deploy'], { cwd: REPO_ROOT, env, stdio: 'pipe' });
  execFileSync('npx', ['prisma', 'db', 'seed'], { cwd: REPO_ROOT, env, stdio: 'pipe' });
}
