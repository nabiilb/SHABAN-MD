/**
 * Playwright's web server for the e2e run: Apache 2.4 + mod_php serving the
 * Hostinger layout of https://lab.sooryoscan.com. The folder public_html/48hrs_lab
 * is assembled from the e2e web build (dist-e2e) and deploy/hostinger/public_html
 * (.htaccess, .user.ini, laravel.php) and is the DOCUMENT ROOT, like the subdomain's:
 * the app is at "/", the API at "/api". The Laravel app is placed next to
 * public_html as 48hrs_lab_app (a symlink to backend/), so laravel.php finds it by
 * its default path, exactly as in production.
 *
 * Env (from playwright.config via stackEnv): E2E_PORT and Laravel's settings.
 */
import { spawn } from 'node:child_process';
import { chmodSync, cpSync, mkdirSync, mkdtempSync, readFileSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../../..');
const backend = join(repo, 'backend');
const port = process.env.E2E_PORT ?? '4100';
const folder = '48hrs_lab'; // on disk only

const work = mkdtempSync(join(tmpdir(), '48hrs-apache-'));
chmodSync(work, 0o755); // Apache's workers run as www-data
const docroot = join(work, 'public_html');
const web = join(docroot, folder);
mkdirSync(web, { recursive: true });
cpSync(join(repo, 'apps/web/dist-e2e'), web, { recursive: true });
for (const f of ['.htaccess', '.user.ini', 'laravel.php', 'assets/.htaccess']) {
  const text = readFileSync(join(repo, 'deploy/hostinger/public_html', f), 'utf8').replaceAll('__BASE_PATH__', '/');
  mkdirSync(dirname(join(web, f)), { recursive: true });
  writeFileSync(join(web, f), text);
}
// The Laravel app next to public_html, found by laravel.php's default ../../48hrs_lab_app.
symlinkSync(backend, join(work, '48hrs_lab_app'));

// Laravel writes caches and compiled files under storage/ and bootstrap/cache (www-data).
for (const dir of ['storage', 'bootstrap/cache']) chmodSync(join(backend, dir), 0o777);
spawn('chmod', ['-R', 'a+rwX', join(backend, 'storage'), join(backend, 'bootstrap/cache')], { stdio: 'inherit' });

const setEnv = Object.entries({ ...(process.env.E2E_LARAVEL_ENV ? JSON.parse(process.env.E2E_LARAVEL_ENV) : {}) })
  .map(([k, v]) => `SetEnv ${k} "${String(v).replaceAll('"', '\\"')}"`)
  .join('\n  ');
const mods = '/usr/lib/apache2/modules';
const conf = `
ServerRoot "${work}"
ServerName localhost
Listen 127.0.0.1:${port}
PidFile "${work}/httpd.pid"
ErrorLog "${work}/error.log"
LogLevel warn
User www-data
Group www-data
LoadModule mpm_prefork_module ${mods}/mod_mpm_prefork.so
LoadModule authz_core_module ${mods}/mod_authz_core.so
LoadModule dir_module ${mods}/mod_dir.so
LoadModule mime_module ${mods}/mod_mime.so
LoadModule rewrite_module ${mods}/mod_rewrite.so
LoadModule headers_module ${mods}/mod_headers.so
LoadModule env_module ${mods}/mod_env.so
LoadModule php_module ${mods}/libphp8.3.so
TypesConfig /etc/mime.types
StartServers 4
MinSpareServers 4
MaxRequestWorkers 20
# Like Hostinger's lab.sooryoscan.com: the document root IS public_html/48hrs_lab.
DocumentRoot "${web}"
<Directory />
  AllowOverride None
  Require all denied
</Directory>
<Directory "${web}">
  AllowOverride All
  Require all granted
  ${setEnv}
</Directory>
<FilesMatch "\\.php$">
  SetHandler application/x-httpd-php
</FilesMatch>
# mod_php does not read .user.ini (PHP-FPM/LiteSpeed do); mirror it here.
php_value upload_max_filesize 64M
php_value post_max_size 64M
`;
writeFileSync(join(work, 'httpd.conf'), conf);
console.log(`[e2e] Apache on http://localhost:${port}/ (document root ${web})`);
const httpd = spawn('/usr/sbin/apache2', ['-f', join(work, 'httpd.conf'), '-DFOREGROUND'], { stdio: 'inherit', env: { ...process.env, APACHE_RUN_DIR: work, APACHE_LOCK_DIR: work, APACHE_LOG_DIR: work } });
const tail = spawn('tail', ['-F', join(work, 'error.log')], { stdio: 'inherit' });
const stop = () => {
  httpd.kill('SIGTERM');
  tail.kill();
};
process.on('SIGTERM', stop);
process.on('SIGINT', stop);
httpd.on('exit', (code) => {
  tail.kill();
  process.exit(code ?? 0);
});
