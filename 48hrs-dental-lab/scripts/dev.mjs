// npm run dev — the whole stack for local development: API (watch mode),
// deadline worker and the Vite dev server (which proxies /api to the API).
// Stop with Ctrl+C; if one process exits, the others are stopped too.
import { spawn } from 'node:child_process';

const processes = [
  { name: 'api', color: 34, args: ['run', 'dev', '-w', '@48hrs/api'] },
  { name: 'worker', color: 35, args: ['run', 'worker', '-w', '@48hrs/api'] },
  { name: 'web', color: 32, args: ['run', 'dev', '-w', '@48hrs/web'] },
];

const children = processes.map(({ name, color, args }) => {
  const child = spawn('npm', args, { stdio: ['ignore', 'pipe', 'pipe'], env: process.env });
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
