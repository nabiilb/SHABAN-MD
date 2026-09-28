import { pino } from 'pino';
import { env, isTest } from '../config/env.ts';

/** Structured JSON logs. Credentials and cookies are redacted. */
export const logger = pino({
  level: isTest ? 'silent' : env.LOG_LEVEL,
  base: { service: '48hrs-api' },
  redact: {
    paths: ['req.headers.cookie', 'req.headers.authorization', 'res.headers["set-cookie"]', '*.password', '*.passwordHash', '*.token'],
    censor: '[redacted]',
  },
});
