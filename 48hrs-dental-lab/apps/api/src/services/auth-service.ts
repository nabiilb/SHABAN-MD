/**
 * Sign-in, sessions and password reset.
 *
 * A session is a database row with an absolute expiry (SESSION_TTL_MINUTES).
 * The browser holds two HTTP-only cookies: a short-lived JWT access token
 * (carrying the session id) and an opaque refresh token stored only as a hash
 * and rotated on every refresh. Revoking the session row ends both at once.
 */
import { ALL_PERMISSION_KEYS } from '@48hrs/shared/permissions';
import { API_ERRORS } from '@48hrs/shared/errors';
import type { AuthSession, ForgotPasswordResult } from '@48hrs/shared/types';
import { env, isProduction } from '../config/env.ts';
import { HttpError, tooManyRequests, unauthorized, unprocessable } from '../lib/errors.ts';
import { sendMail } from '../lib/mailer.ts';
import { dummyPasswordHash, hashPassword, verifyPassword } from '../lib/password.ts';
import { prisma } from '../lib/prisma.ts';
import { randomToken, sha256, signAccessToken } from '../lib/tokens.ts';
import { logActivity } from '../repositories/activity-repository.ts';
import { toUser, userInclude } from '../repositories/mappers.ts';
import type { AuthContext } from '../types/auth.ts';

const RESET_TTL_MS = 60 * 60_000;
const INVALID_LOGIN = 'Email or password not recognised.';
const INVALID_RESET = 'This reset link is invalid or has expired.';

export interface IssuedSession {
  session: AuthSession;
  tokens: { access: string; refresh: string };
  expiresAt: Date;
}

export interface ClientInfo {
  ip?: string;
  userAgent?: string;
}

const sessionUserInclude = { ...userInclude, role: { include: { permissions: { select: { permissionKey: true } } } } } as const;

type SessionUser = NonNullable<Awaited<ReturnType<typeof loadUser>>>;

function loadUser(id: string) {
  return prisma.user.findUnique({ where: { id }, include: sessionUserInclude });
}

/** A locked role (Super Admin) always holds the whole catalogue. */
function permissionsOf(u: SessionUser) {
  return u.role.locked ? [...ALL_PERMISSION_KEYS] : u.role.permissions.map((p) => p.permissionKey);
}

function toAuthSession(u: SessionUser, expiresAt: Date): AuthSession {
  return { expiresAt: expiresAt.toISOString(), user: toUser(u), permissions: permissionsOf(u) };
}

async function issue(user: SessionUser, sessionId: string, refresh: string, expiresAt: Date, now: number): Promise<IssuedSession> {
  const access = await signAccessToken({ sub: user.id, sid: sessionId }, now);
  return { session: toAuthSession(user, expiresAt), tokens: { access, refresh }, expiresAt };
}

export const authService = {
  async login(email: string, password: string, client: ClientInfo): Promise<IssuedSession> {
    const now = Date.now();
    const user = await prisma.user.findUnique({ where: { email: email.trim().toLowerCase() }, include: sessionUserInclude });
    if (!user) {
      // Same work as a real check, so response time does not reveal which e-mails exist.
      await verifyPassword(password, await dummyPasswordHash());
      throw unauthorized(INVALID_LOGIN);
    }
    if (user.lockedUntil && user.lockedUntil.getTime() > now) throw tooManyRequests();

    if (!(await verifyPassword(password, user.passwordHash))) {
      const failed = user.failedLogins + 1;
      const lock = failed >= env.LOGIN_MAX_ATTEMPTS;
      await prisma.user.update({
        where: { id: user.id },
        data: { failedLogins: lock ? 0 : failed, lockedUntil: lock ? new Date(now + env.LOGIN_LOCK_MINUTES * 60_000) : user.lockedUntil },
      });
      throw lock ? tooManyRequests() : unauthorized(INVALID_LOGIN);
    }
    // Only reveal "disabled" after the correct password, so it cannot be used to probe accounts.
    if (!user.active) throw new HttpError(403, 'This account has been disabled by the Super Admin.');

    const refresh = randomToken();
    const expiresAt = new Date(now + env.SESSION_TTL_MINUTES * 60_000);
    const session = await prisma.$transaction(async (tx) => {
      await tx.user.update({ where: { id: user.id }, data: { failedLogins: 0, lockedUntil: null, lastLoginAt: new Date(now) } });
      await logActivity(tx, user, { action: 'auth.login', description: 'Signed in', subjectType: 'auth' });
      return tx.session.create({
        data: { userId: user.id, refreshTokenHash: sha256(refresh), expiresAt, userAgent: client.userAgent?.slice(0, 300), ip: client.ip },
      });
    });
    return issue(user, session.id, refresh, expiresAt, now);
  },

  /** Rotates the refresh token and issues a new access token; the session's absolute expiry is kept. */
  async refresh(refreshToken: string | undefined): Promise<IssuedSession> {
    if (!refreshToken) throw unauthorized(API_ERRORS.sessionExpired);
    const now = Date.now();
    const session = await prisma.session.findUnique({ where: { refreshTokenHash: sha256(refreshToken) } });
    if (!session || session.revokedAt || session.expiresAt.getTime() <= now) throw unauthorized(API_ERRORS.sessionExpired);
    const user = await loadUser(session.userId);
    if (!user?.active) {
      await prisma.session.update({ where: { id: session.id }, data: { revokedAt: new Date(now) } });
      throw unauthorized(API_ERRORS.sessionExpired);
    }
    const next = randomToken();
    // Conditional update: a refresh token can be used once, even by two concurrent requests.
    const { count } = await prisma.session.updateMany({
      where: { id: session.id, refreshTokenHash: session.refreshTokenHash, revokedAt: null },
      data: { refreshTokenHash: sha256(next), lastUsedAt: new Date(now) },
    });
    if (!count) throw unauthorized(API_ERRORS.sessionExpired);
    return issue(user, session.id, next, session.expiresAt, now);
  },

  async logout(sessionId: string | undefined, refreshToken: string | undefined) {
    const now = new Date();
    if (sessionId) await prisma.session.updateMany({ where: { id: sessionId, revokedAt: null }, data: { revokedAt: now } });
    if (refreshToken) await prisma.session.updateMany({ where: { refreshTokenHash: sha256(refreshToken), revokedAt: null }, data: { revokedAt: now } });
  },

  /**
   * Resolves an access token's claims to the caller. Returns null when the
   * session was revoked or expired, or the user was disabled — access tokens
   * die with their session, not only when their own expiry passes.
   */
  async resolve(userId: string, sessionId: string): Promise<AuthContext | null> {
    const session = await prisma.session.findUnique({ where: { id: sessionId }, include: { user: { include: sessionUserInclude } } });
    if (!session || session.userId !== userId || session.revokedAt || session.expiresAt.getTime() <= Date.now() || !session.user.active) return null;
    return { user: toUser(session.user), permissions: permissionsOf(session.user), sessionId: session.id, sessionExpiresAt: session.expiresAt };
  },

  async me(auth: AuthContext): Promise<AuthSession> {
    return { expiresAt: auth.sessionExpiresAt.toISOString(), user: auth.user, permissions: auth.permissions };
  },

  /** Always answers the same way, so it cannot be used to discover accounts. */
  async forgotPassword(email: string): Promise<ForgotPasswordResult> {
    const result: ForgotPasswordResult = { message: 'If an account exists for that email, a reset link has been sent.' };
    const user = await prisma.user.findUnique({ where: { email: email.trim().toLowerCase() } });
    if (!user?.active) return result;
    const token = randomToken();
    await prisma.$transaction([
      prisma.passwordResetToken.deleteMany({ where: { userId: user.id, usedAt: null } }),
      prisma.passwordResetToken.create({ data: { userId: user.id, tokenHash: sha256(token), expiresAt: new Date(Date.now() + RESET_TTL_MS) } }),
    ]);
    const path = `/reset-password?token=${encodeURIComponent(token)}&email=${encodeURIComponent(user.email)}`;
    await sendMail({
      to: user.email,
      subject: 'Reset your 48HRS Dental Lab password',
      text: `Hello ${user.name},\n\nUse this link within one hour to choose a new password:\n${env.APP_URL}${path}\n\nIf you did not ask for this, ignore this e-mail.`,
    });
    // Development convenience only: with the log transport there is no inbox to open.
    if (env.MAIL_TRANSPORT === 'log' && !isProduction) result.devResetUrl = path;
    return result;
  },

  async resetPassword(input: { token: string; email: string; password: string }) {
    const now = new Date();
    const rec = await prisma.passwordResetToken.findUnique({ where: { tokenHash: sha256(input.token) }, include: { user: true } });
    if (!rec || rec.usedAt || rec.expiresAt <= now || rec.user.email !== input.email.trim().toLowerCase()) throw unprocessable(INVALID_RESET);
    const passwordHash = await hashPassword(input.password);
    await prisma.$transaction(async (tx) => {
      const { count } = await tx.passwordResetToken.updateMany({ where: { id: rec.id, usedAt: null }, data: { usedAt: now } });
      if (!count) throw unprocessable(INVALID_RESET);
      await tx.user.update({ where: { id: rec.userId }, data: { passwordHash, failedLogins: 0, lockedUntil: null } });
      // Every existing sign-in ends: whoever knew the old password is logged out.
      await tx.session.updateMany({ where: { userId: rec.userId, revokedAt: null }, data: { revokedAt: now } });
      await logActivity(tx, rec.user, { action: 'auth.password_reset', description: 'Reset password', subjectType: 'auth' });
    });
    return { message: 'Your password has been reset. You can sign in now.' };
  },
};
