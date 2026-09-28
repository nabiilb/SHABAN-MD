/**
 * The per-IP sign-in limit (AUTH_RATE_LIMIT) on the real app: this file loads
 * the app with a low limit (each test file has its own module graph).
 */
import { describe, expect, it } from 'vitest';

process.env.AUTH_RATE_LIMIT = '5';
const { default: request } = await import('supertest');
const { app, PASSWORD, USERS } = await import('./helpers.ts');

const attempt = (email: string, password: string, headers: Record<string, string> = {}) =>
  request(app).post('/api/auth/login').set('X-Requested-With', 'XMLHttpRequest').set(headers).send({ email, password });

describe('authentication rate limiting', () => {
  it('after AUTH_RATE_LIMIT attempts from one IP, sign-in is refused with 429 — even with the right password', async () => {
    for (let i = 0; i < 5; i++) {
      const res = await attempt(`nobody-${i}@example.com`, 'Wrong-pass-1'); // unknown accounts: no per-account lockout involved
      expect(res.status).toBe(401);
      expect(res.headers.ratelimit ?? res.headers['ratelimit-policy']).toBeTruthy();
    }
    const blocked = await attempt(USERS.admin, PASSWORD);
    expect(blocked.status).toBe(429);
    expect(blocked.body).toEqual({ message: expect.stringMatching(/too many/i) });
    expect(blocked.headers['set-cookie']).toBeUndefined(); // no session was issued
  });

  it('a spoofed X-Forwarded-For does not reset the limit (TRUST_PROXY=0)', async () => {
    const res = await attempt(USERS.admin, PASSWORD, { 'X-Forwarded-For': '203.0.113.9' });
    expect(res.status).toBe(429);
  });

  it('the limit covers only the auth endpoints: other API calls still answer', async () => {
    expect((await request(app).get('/api/health')).status).toBe(200);
    expect((await request(app).get('/api/auth/me')).status).toBe(401);
  });
});
