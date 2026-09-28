import { createHash, randomBytes } from 'node:crypto';
import { SignJWT, jwtVerify, errors as joseErrors } from 'jose';
import { env } from '../config/env.ts';

const secret = new TextEncoder().encode(env.JWT_SECRET);
const ISSUER = '48hrs-dental-lab';
const AUDIENCE = '48hrs-dental-lab:api';

export interface AccessClaims {
  /** User id. */
  sub: string;
  /** Session id — lets a revoked session invalidate its access tokens immediately. */
  sid: string;
}

export async function signAccessToken(claims: AccessClaims, now = Date.now()) {
  const iat = Math.floor(now / 1000);
  return new SignJWT({ sid: claims.sid })
    .setProtectedHeader({ alg: 'HS256', typ: 'JWT' })
    .setSubject(claims.sub)
    .setIssuer(ISSUER)
    .setAudience(AUDIENCE)
    .setIssuedAt(iat)
    .setExpirationTime(iat + env.ACCESS_TOKEN_TTL_MINUTES * 60)
    .sign(secret);
}

export type VerifyResult = { ok: true; claims: AccessClaims } | { ok: false; expired: boolean };

export async function verifyAccessToken(token: string): Promise<VerifyResult> {
  try {
    const { payload } = await jwtVerify(token, secret, { issuer: ISSUER, audience: AUDIENCE, algorithms: ['HS256'], currentDate: new Date() });
    if (typeof payload.sub !== 'string' || typeof payload.sid !== 'string') return { ok: false, expired: false };
    return { ok: true, claims: { sub: payload.sub, sid: payload.sid } };
  } catch (err) {
    return { ok: false, expired: err instanceof joseErrors.JWTExpired };
  }
}

/** URL-safe random token (refresh tokens, password-reset tokens, file keys). */
export function randomToken(bytes = 32) {
  return randomBytes(bytes).toString('base64url');
}

/** Tokens are stored only as SHA-256 digests, so a database leak does not leak live sessions. */
export function sha256(value: string) {
  return createHash('sha256').update(value).digest('hex');
}
