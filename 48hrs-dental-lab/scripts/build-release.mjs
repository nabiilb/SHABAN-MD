#!/usr/bin/env node
/**
 * Builds the Hostinger release (the server has PHP, MySQL and git, but no Node/npm).
 *
 * Production: https://lab.sooryoscan.com — a subdomain whose DOCUMENT ROOT is the
 * folder public_html/48hrs_lab. That folder name is only a place on disk: it never
 * appears in a URL. The app is served from "/" and the API from "/api".
 *
 *   release/
 *     public_html/48hrs_lab/   → ~/domains/sooryoscan.com/public_html/48hrs_lab/  (the subdomain's document root)
 *       index.html, assets/…     React build (VITE_BASE_PATH=/, VITE_API_URL=/api)
 *       .htaccess, .user.ini     SPA fallback, /api → laravel.php, PHP upload limits
 *       laravel.php              front controller: boots Laravel from ../../48hrs_lab_app
 *     48hrs_lab_app/           → ~/domains/sooryoscan.com/48hrs_lab_app/  (outside public_html)
 *       Laravel (backend/) with vendor/ (composer --no-dev), without .env, tests or logs
 *   release/48hrs-lab-release.tar.gz   both folders, to extract in ~/domains/sooryoscan.com/
 *
 * Usage: npm run release [-- --folder=48hrs_lab --app=48hrs_lab_app --base=/]
 *   --folder  document-root folder under public_html (filesystem only)
 *   --app     Laravel folder next to public_html (filesystem only)
 *   --base    URL path the app is served from: "/" for a (sub)domain root
 * Needs Node and Composer on the machine that builds (not on the server).
 */
import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const arg = (name, fallback) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split('=')[1] ?? fallback;
const trimmed = arg('base', '/').replace(/^\/+|\/+$/g, '');
const base = trimmed ? `/${trimmed}/` : '/';
const folder = arg('folder', '48hrs_lab');
const appName = arg('app', '48hrs_lab_app');
for (const [name, v] of [['--folder', folder], ['--app', appName], ['--base', trimmed || 'x']]) {
  if (!/^[A-Za-z0-9._\-/]+$/.test(v) || v.includes('..')) throw new Error(`Invalid ${name} ${v}`);
}

const out = join(root, 'release');
const web = join(out, 'public_html', folder);
const app = join(out, appName);
const run = (cmd, args, opts = {}) => execFileSync(cmd, args, { stdio: 'inherit', cwd: root, ...opts });

rmSync(out, { recursive: true, force: true });
mkdirSync(web, { recursive: true });

// 1. React build for the sub-folder, talking to the Laravel API next to it.
console.log(`• web build → public_html/${folder} (served at ${base}, API ${base}api)`);
run('npx', ['vite', 'build', '--outDir', web, '--emptyOutDir', '--logLevel', 'warn'], {
  cwd: join(root, 'apps/web'),
  env: { ...process.env, VITE_USE_MOCKS: 'false', VITE_BASE_PATH: base, VITE_API_URL: `${base}api` },
});
for (const f of ['.htaccess', '.user.ini', 'laravel.php', 'assets/.htaccess']) {
  const src = readFileSync(join(root, 'deploy/hostinger/public_html', f), 'utf8');
  const text = src.replaceAll('__BASE_PATH__', base).replace("'/48hrs_lab_app'", `'/${appName}'`);
  mkdirSync(dirname(join(web, f)), { recursive: true });
  writeFileSync(join(web, f), text);
}

// 2. Laravel: tracked (and new, not ignored) files only — never .env, vendor, logs or uploads.
console.log(`• Laravel → ${appName}`);
const files = execFileSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', 'backend'], { cwd: root, encoding: 'utf8' })
  .split('\n')
  .filter(Boolean)
  .filter((f) => !/^backend\/(tests|phpunit\.xml|\.env(\..*)?$|storage\/logs\/.+\.log$)/.test(f) || f === 'backend/.env.example');
for (const f of files) {
  if (!existsSync(join(root, f))) continue; // deleted in the working tree
  const target = join(app, f.slice('backend/'.length));
  mkdirSync(dirname(target), { recursive: true });
  cpSync(join(root, f), target);
}
run('composer', ['install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress', '--no-scripts'], { cwd: app, env: { ...process.env, COMPOSER_ALLOW_SUPERUSER: '1' } });
// When dist archives cannot be downloaded, Composer clones sources: drop their .git folders.
run('find', ['vendor', '-name', '.git', '-type', 'd', '-prune', '-exec', 'rm', '-rf', '{}', '+'], { cwd: app });
run('composer', ['dump-autoload', '--no-dev', '--optimize', '--no-scripts'], { cwd: app, env: { ...process.env, COMPOSER_ALLOW_SUPERUSER: '1' } });

// 3. One archive for the upload.
run('tar', ['-czf', join(out, '48hrs-lab-release.tar.gz'), '-C', out, 'public_html', appName]);
console.log(`\nRelease ready: release/48hrs-lab-release.tar.gz (app at ${base}, API at ${base}api)\n  public_html/${folder}/  → ~/domains/<domain>/public_html/${folder}/  (document root)\n  ${appName}/  → ~/domains/<domain>/${appName}/`);
