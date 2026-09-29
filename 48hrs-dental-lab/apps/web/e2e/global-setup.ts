import { execFileSync } from 'node:child_process';
import mysql from 'mysql2/promise';
import { BACKEND, stackEnv } from './stack-env';

/** Fresh MySQL database for the run: every migration from empty, then the demo lab. */
export default async function globalSetup() {
  const env = stackEnv();
  const conn = await mysql.createConnection({ host: env.DB_HOST, port: Number(env.DB_PORT), user: env.DB_USERNAME, password: env.DB_PASSWORD });
  await conn.query(`DROP DATABASE IF EXISTS \`${env.DB_DATABASE}\``);
  await conn.query(`CREATE DATABASE \`${env.DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  await conn.end();
  const run = (args: string[]) => execFileSync('php', ['artisan', ...args], { cwd: BACKEND, env: { ...process.env, ...env }, stdio: 'pipe' });
  run(['migrate', '--force']);
  run(['db:seed', '--force']);
  // The seed ran as this user; Apache (www-data) writes new uploads next to its files.
  execFileSync('chmod', ['-R', 'a+rwX', env.UPLOAD_DIR]);
}
