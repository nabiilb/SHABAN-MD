import { rateLimit } from 'express-rate-limit';
import { API_ERRORS } from '@48hrs/shared/errors';
import { env, isTest } from '../config/env.ts';

const handler = { message: API_ERRORS.rateLimited };

/**
 * Per-IP limits for unauthenticated auth endpoints. Per-account lockout after
 * repeated wrong passwords is enforced in the database by the auth service.
 * The in-memory store is per process; use a shared store when scaling out.
 */
export const loginLimiter = rateLimit({
  windowMs: 15 * 60_000,
  limit: isTest ? 1_000 : env.AUTH_RATE_LIMIT,
  standardHeaders: 'draft-8',
  legacyHeaders: false,
  message: handler,
});

export const passwordResetLimiter = rateLimit({
  windowMs: 60 * 60_000,
  limit: isTest ? 1_000 : 10,
  standardHeaders: 'draft-8',
  legacyHeaders: false,
  message: handler,
});

/** A generous ceiling for the whole API, against scripted abuse. */
export const apiLimiter = rateLimit({
  windowMs: 60_000,
  limit: isTest ? 100_000 : 600,
  standardHeaders: 'draft-8',
  legacyHeaders: false,
  message: handler,
});
