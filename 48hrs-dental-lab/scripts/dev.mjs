// npm run dev — the whole stack for local development: the Laravel API
// (php artisan serve), the queue worker, the scheduler and the Vite dev server
// (which proxies /api to Laravel). Stop with Ctrl+C; if one process exits, the
// others are stopped too. Needs PHP, Composer dependencies (backend/vendor),
// backend/.env and a migrated MySQL database — see README.md.
import { spawn } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const backend = join(dirname(fileURLToPath(import.meta.url)), '..', 'backend');
const processes = [
  { name: 'api', color: 34, cmd: 'php', args: ['artisan', 'serve', '--host=127.0.0.1', '--port=8000'], cwd: backend, env: { PHP_CLI_SERVER_WORKERS: '4' } },
  { name: 'queue', color: 35, cmd: 'php', args: ['artisan', 'queue:work', '--sleep=1', '--tries=3'], cwd: backend },
  { name: 'sched', color: 33, cmd: 'php', args: ['artisan', 'schedule:work'], cwd: backend },
  { name: 'web', color: 32, cmd: 'npm', args: ['run', 'dev', '-w', '@48hrs/web'] },
];

const children = processes.map(({ name, color, cmd, args, cwd, env }) => {
  const child = spawn(cmd, args, { cwd, stdio: ['ignore', 'pipe', 'pipe'], env: { ...process.env, ...env } });
  const prefix = `\x1b[${color}m${name.padEnd(6)}\x1b[0m│ `;
  const relay = (stream, out) => stream.on('data', (chunk) => out.write(chunk.toString().replace(/^(?=.)/gm, prefix)));
  relay(child.stdout, process.stdout);
  relay(child.stderr, process.stderr);
  child.on('exit', (code) => {
    process.stdout.write(`${prefix}exited (${code ?? 'signal'})\n`);
    shutdown(code ?? 0);
  });
  return child;
});

let stopping = false;
function shutdown(code) {
  if (stopping) return;
  stopping = true;
  children.forEach((c) => c.exitCode === null && c.kill('SIGTERM'));
  setTimeout(() => process.exit(code), 500);
}

process.on('SIGINT', () => shutdown(0));
process.on('SIGTERM', () => shutdown(0));
