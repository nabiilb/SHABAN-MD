import { env } from '@/config/env';
import { ApiError } from '@/services/api/errors';
import type { AuthSession } from '@/types/models';
import type { ForgotPasswordResult, LoginPayload, ResetPasswordPayload } from '@/types/api';
import { authenticate, permissionsFor, publicUser } from '../auth-context';
import { logActivity } from '../domain';
import { hashPassword, randomHex } from '../sha256';
import { EMAIL_RE, requireFields, route, validationError } from '../router';

const MAX_ATTEMPTS = 5;
const LOCK_MS = 60_000;
const attempts = new Map<string, { count: number; until: number }>();

route('POST', '/auth/login', (ctx) => {
  const body = (ctx.body ?? {}) as Partial<LoginPayload>;
  requireFields(body, { email: 'Email', password: 'Password' });
  const email = String(body.email).trim().toLowerCase();

  const lock = attempts.get(email);
  if (lock && lock.until > ctx.now) throw new ApiError(429, 'Too many attempts. Wait a minute and try again.');

  const user = ctx.db.users.find((u) => u.email.toLowerCase() === email);
  const ok = user && hashPassword(String(body.password), user.passwordSalt) === user.passwordHash;
  if (!user || !ok) {
    const a = attempts.get(email) ?? { count: 0, until: 0 };
    a.count += 1;
    if (a.count >= MAX_ATTEMPTS) {
      a.until = ctx.now + LOCK_MS;
      a.count = 0;
    }
    attempts.set(email, a);
    throw new ApiError(401, 'Email or password not recognised.');
  }
  if (!user.active) throw new ApiError(403, 'This account has been disabled by the Super Admin.');

  attempts.delete(email);
  const token = randomHex(24);
  const expiresAt = new Date(ctx.now + env.sessionTimeoutMinutes * 60_000).toISOString();
  ctx.db.sessions.push({ token, userId: user.id, expiresAt });
  user.lastLoginAt = new Date(ctx.now).toISOString();
  logActivity(ctx.db, user, { action: 'auth.login', description: 'Signed in', subjectType: 'auth' }, ctx.now);

  const session: AuthSession = { token, expiresAt, user: publicUser(user), permissions: permissionsFor(ctx.db, user) };
  return session;
});

route('POST', '/auth/logout', (ctx) => {
  ctx.db.sessions = ctx.db.sessions.filter((s) => s.token !== ctx.token);
  return null;
});

route('GET', '/auth/me', (ctx) => {
  const a = authenticate(ctx);
  const session: AuthSession = { token: a.session.token, expiresAt: a.session.expiresAt, user: publicUser(a.user), permissions: a.permissions };
  return session;
});

route('POST', '/auth/forgot-password', (ctx) => {
  const body = (ctx.body ?? {}) as { email?: string };
  requireFields(body, { email: 'Email' });
  const email = String(body.email).trim().toLowerCase();
  if (!EMAIL_RE.test(email)) throw validationError({ email: ['Enter a valid email address.'] });

  const result: ForgotPasswordResult = {
    // Same response whether or not the account exists, so emails cannot be enumerated.
    message: 'If an account exists for that email, a reset link has been sent.',
  };
  const user = ctx.db.users.find((u) => u.email.toLowerCase() === email && u.active);
  if (user) {
    const token = randomHex(20);
    ctx.db.resetTokens = ctx.db.resetTokens.filter((t) => t.email !== email);
    ctx.db.resetTokens.push({ token, email, expiresAt: new Date(ctx.now + 60 * 60_000).toISOString() });
    // The mock cannot send e-mail, so it hands the link back for local testing.
    result.devResetUrl = `/reset-password?token=${token}&email=${encodeURIComponent(email)}`;
  }
  return result;
});

route('POST', '/auth/reset-password', (ctx) => {
  const body = (ctx.body ?? {}) as Partial<ResetPasswordPayload>;
  requireFields(body, { token: 'Reset token', email: 'Email', password: 'Password', passwordConfirmation: 'Password confirmation' });
  const password = String(body.password);
  if (password.length < 8) throw validationError({ password: ['Use at least 8 characters.'] });
  if (password !== body.passwordConfirmation) throw validationError({ passwordConfirmation: ['Passwords do not match.'] });

  const email = String(body.email).trim().toLowerCase();
  const rec = ctx.db.resetTokens.find((t) => t.token === body.token && t.email === email);
  if (!rec || new Date(rec.expiresAt).getTime() < ctx.now) throw new ApiError(422, 'This reset link is invalid or has expired.');

  const user = ctx.db.users.find((u) => u.email.toLowerCase() === email);
  if (!user) throw new ApiError(422, 'This reset link is invalid or has expired.');
  user.passwordSalt = randomHex(8);
  user.passwordHash = hashPassword(password, user.passwordSalt);
  ctx.db.resetTokens = ctx.db.resetTokens.filter((t) => t !== rec);
  ctx.db.sessions = ctx.db.sessions.filter((s) => s.userId !== user.id);
  logActivity(ctx.db, user, { action: 'auth.password_reset', description: 'Reset password', subjectType: 'auth' }, ctx.now);
  return { message: 'Your password has been reset. You can sign in now.' };
});
