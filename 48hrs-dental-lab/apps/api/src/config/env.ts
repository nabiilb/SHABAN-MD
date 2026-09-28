/**
 * Typed, validated configuration. The process refuses to start with a missing
 * or weak secret instead of silently running insecurely. Values come from the
 * environment (a local .env file in development, the platform in production).
 */
import { existsSync, readFileSync } from 'node:fs';
import { dirname, isAbsolute, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { z } from 'zod';

/** apps/api — found by walking up from this module, so it is right from src/ and dist/ alike. */
function findApiRoot() {
  let dir = dirname(fileURLToPath(import.meta.url));
  for (let i = 0; i < 6; i++) {
    const pkg = resolve(dir, 'package.json');
    if (existsSync(pkg) && (JSON.parse(readFileSync(pkg, 'utf8')) as { name?: string }).name === '@48hrs/api') return dir;
    dir = dirname(dir);
  }
  return process.cwd();
}

export const API_ROOT = findApiRoot();

const bool = (fallback: boolean) =>
  z
    .enum(['true', 'false', '1', '0', ''])
    .optional()
    .transform((v) => (v === undefined || v === '' ? fallback : v === 'true' || v === '1'));

const list = z
  .string()
  .optional()
  .transform((v) => (v ?? '').split(',').map((s) => s.trim()).filter(Boolean));

const schema = z
  .object({
    NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
    PORT: z.coerce.number().int().min(1).max(65535).default(4000),
    DATABASE_URL: z.string().url('DATABASE_URL must be a postgresql:// URL'),
    /** Browser origins allowed to call the API with credentials (comma-separated). */
    CORS_ORIGINS: list,
    /** HMAC secret for access tokens — at least 32 characters of randomness. */
    JWT_SECRET: z.string().min(32, 'JWT_SECRET must be at least 32 characters'),
    ACCESS_TOKEN_TTL_MINUTES: z.coerce.number().int().min(1).max(120).default(15),
    /** Absolute session lifetime; refreshing never extends it. */
    SESSION_TTL_MINUTES: z.coerce.number().int().min(5).max(60 * 24 * 30).default(480),
    COOKIE_SECURE: bool(true),
    COOKIE_SAMESITE: z.enum(['lax', 'strict', 'none']).default('lax'),
    COOKIE_DOMAIN: z.string().optional().transform((v) => v || undefined),
    /** Number of reverse proxies in front of the API (for client IPs and secure cookies). */
    TRUST_PROXY: z.coerce.number().int().min(0).max(10).default(0),
    /** IANA zone that defines the lab's working day (due today, reports, dashboard periods). */
    LAB_TIMEZONE: z.string().default('UTC'),
    /** Relative paths are resolved against apps/api, whatever the working directory. */
    UPLOAD_DIR: z
      .string()
      .default('./storage/uploads')
      .transform((v) => (isAbsolute(v) ? v : resolve(API_ROOT, v))),
    MAX_UPLOAD_MB: z.coerce.number().int().min(1).max(200).default(50),
    /** Optional: serve the built web app (apps/web/dist) from this process, same origin as the API. */
    WEB_DIST_DIR: z.string().optional().transform((v) => v || undefined),
    /** Public URL of the web app, used in password-reset links. */
    APP_URL: z.string().url().default('http://localhost:5173'),
    MAIL_TRANSPORT: z.enum(['log', 'smtp']).default('log'),
    MAIL_FROM: z.string().default('48HRS Dental Lab <no-reply@localhost>'),
    SMTP_URL: z.string().optional(),
    LOG_LEVEL: z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent']).default('info'),
    WORKER_INTERVAL_SECONDS: z.coerce.number().int().min(10).max(3600).default(60),
    LOGIN_MAX_ATTEMPTS: z.coerce.number().int().min(3).max(50).default(5),
    /** Sign-in attempts per client IP per 15 minutes (a whole clinic may share one IP). */
    AUTH_RATE_LIMIT: z.coerce.number().int().min(5).max(100_000).default(100),
    LOGIN_LOCK_MINUTES: z.coerce.number().int().min(1).max(120).default(15),
  })
  .superRefine((v, ctx) => {
    try {
      new Intl.DateTimeFormat('en-US', { timeZone: v.LAB_TIMEZONE });
    } catch {
      ctx.addIssue({ code: 'custom', path: ['LAB_TIMEZONE'], message: `Unknown time zone "${v.LAB_TIMEZONE}"` });
    }
    if (v.MAIL_TRANSPORT === 'smtp' && !v.SMTP_URL) ctx.addIssue({ code: 'custom', path: ['SMTP_URL'], message: 'SMTP_URL is required when MAIL_TRANSPORT=smtp' });
    if (v.NODE_ENV === 'production') {
      if (!v.COOKIE_SECURE) ctx.addIssue({ code: 'custom', path: ['COOKIE_SECURE'], message: 'Cookies must be Secure in production' });
      if (!v.CORS_ORIGINS.length) ctx.addIssue({ code: 'custom', path: ['CORS_ORIGINS'], message: 'Set the allowed web origin(s) in production' });
      if (v.MAIL_TRANSPORT === 'log') ctx.addIssue({ code: 'custom', path: ['MAIL_TRANSPORT'], message: 'Configure SMTP in production so reset links are e-mailed, not logged' });
      if (!/^https:\/\//.test(v.APP_URL) || /localhost|127\.0\.0\.1/.test(v.APP_URL)) ctx.addIssue({ code: 'custom', path: ['APP_URL'], message: 'Set APP_URL to the public https:// address of the web app' });
      if (v.CORS_ORIGINS.some((o) => !o.startsWith('https://'))) ctx.addIssue({ code: 'custom', path: ['CORS_ORIGINS'], message: 'Production origins must be https://' });
      if (/localhost/.test(v.MAIL_FROM)) ctx.addIssue({ code: 'custom', path: ['MAIL_FROM'], message: 'Set MAIL_FROM to a real sender address' });
    }
    if (v.COOKIE_SAMESITE === 'none' && !v.COOKIE_SECURE) ctx.addIssue({ code: 'custom', path: ['COOKIE_SAMESITE'], message: 'SameSite=None requires COOKIE_SECURE=true' });
  });

export type Env = z.infer<typeof schema>;

/** Validates a configuration source; throws with every problem listed. Exported for the config tests. */
export function parseEnv(source: Record<string, string | undefined>): Env {
  const parsed = schema.safeParse(source);
  if (!parsed.success) {
    const lines = parsed.error.issues.map((i) => `  - ${i.path.join('.') || 'env'}: ${i.message}`);
    throw new Error(`Invalid configuration:\n${lines.join('\n')}`);
  }
  return parsed.data;
}

export const env = parseEnv(process.env);
export const isProduction = env.NODE_ENV === 'production';
export const isTest = env.NODE_ENV === 'test';
