import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { Client, login, PASSWORD, prisma, resetDb, USERS } from './helpers.ts';

const MIN = 60_000;

beforeAll(() => resetDb());
afterEach(() => vi.useRealTimers());

function setCookies(res: { headers: Record<string, unknown> }) {
  return (res.headers['set-cookie'] as string[] | undefined) ?? [];
}

function cookieNames(res: { headers: Record<string, unknown> }) {
  return setCookies(res).map((c) => c.split('=')[0]);
}

function cookieValue(res: { headers: Record<string, unknown> }, name: string) {
  return setCookies(res).find((c) => c.startsWith(`${name}=`))?.split(';')[0].slice(name.length + 1) ?? '';
}

describe('login', () => {
  it('signs in with HTTP-only cookies and returns the session, never a token', async () => {
    const c = new Client();
    const res = await c.post('/auth/login', { email: USERS.admin, password: PASSWORD });
    expect(res.status).toBe(200);
    expect(res.body.user).toMatchObject({ email: USERS.admin, role: 'admin' });
    expect(res.body.token).toBeUndefined();
    expect(res.body.permissions).toContain('cases.view');
    expect(new Date(res.body.expiresAt).getTime()).toBeGreaterThan(Date.now() + 7 * 60 * MIN);
    const cookies = (res.headers['set-cookie'] as unknown as string[]).join('\n');
    expect(cookieNames(res)).toEqual(['lab_access', 'lab_refresh']);
    expect(cookies).toMatch(/lab_access=[^;]+;.*HttpOnly/);
    expect(cookies).toMatch(/lab_refresh=[^;]+;.*Path=\/api\/auth.*HttpOnly/);
    expect(cookies).toMatch(/SameSite=Lax/);
    expect((await c.get('/auth/me')).body.user.email).toBe(USERS.admin);
  });

  it('rejects bad credentials with 401 and the same message for unknown e-mails', async () => {
    const wrong = await new Client().post('/auth/login', { email: USERS.admin, password: 'not-the-password1' });
    const unknown = await new Client().post('/auth/login', { email: 'nobody@48hrs.lab', password: 'whatever12' });
    expect(wrong.status).toBe(401);
    expect(unknown.status).toBe(401);
    expect(wrong.body.message).toBe(unknown.body.message);
  });

  it('validates the body (422)', async () => {
    const res = await new Client().post('/auth/login', { email: 'not-an-email' });
    expect(res.status).toBe(422);
    expect(Object.keys(res.body.errors)).toEqual(expect.arrayContaining(['email', 'password']));
  });

  it('refuses disabled accounts only after the correct password (403)', async () => {
    expect((await new Client().post('/auth/login', { email: USERS.disabledClient, password: 'wrong-pass1' })).status).toBe(401);
    const res = await new Client().post('/auth/login', { email: USERS.disabledClient, password: PASSWORD });
    expect(res.status).toBe(403);
    expect(res.body.message).toMatch(/disabled/);
  });

  it('locks the account after repeated failures (429), stored in the database', async () => {
    for (let i = 0; i < 4; i++) expect((await new Client().post('/auth/login', { email: USERS.delivery, password: `bad-${i}-pass` })).status).toBe(401);
    expect((await new Client().post('/auth/login', { email: USERS.delivery, password: 'bad-5-pass' })).status).toBe(429);
    expect((await new Client().post('/auth/login', { email: USERS.delivery, password: PASSWORD })).status).toBe(429);
    const u = await prisma.user.findUniqueOrThrow({ where: { email: USERS.delivery } });
    expect(u.lockedUntil!.getTime()).toBeGreaterThan(Date.now());
    await prisma.user.update({ where: { id: u.id }, data: { lockedUntil: null } });
    expect((await new Client().post('/auth/login', { email: USERS.delivery, password: PASSWORD })).status).toBe(200);
  });

  it('never stores plain-text passwords', async () => {
    const u = await prisma.user.findUniqueOrThrow({ where: { email: USERS.admin } });
    expect(u.passwordHash).toMatch(/^scrypt\$/);
    expect(u.passwordHash).not.toContain(PASSWORD);
  });
});

describe('protected routes', () => {
  it('401 without a session, 401 with a forged token', async () => {
    const anon = new Client();
    for (const path of ['/auth/me', '/cases', '/dashboard', '/users', '/notifications']) {
      const res = await anon.get(path);
      expect(res.status, path).toBe(401);
      expect(res.body.message).toBeTruthy();
    }
    const forged = await anon.agent.get('/api/cases').set('Cookie', 'lab_access=eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ4In0.bad');
    expect(forged.status).toBe(401);
  });

  it('requires the CSRF header on state-changing requests (403)', async () => {
    const c = await login(USERS.admin);
    const res = await c.agent.post('/api/notifications/read-all');
    expect(res.status).toBe(403);
    expect(res.body).toEqual({ message: 'Access restricted.' });
    const foreign = await c.agent.post('/api/notifications/read-all').set('X-Requested-With', 'XMLHttpRequest').set('Origin', 'https://evil.example');
    expect(foreign.status).toBe(403);
    expect((await c.post('/notifications/read-all')).status).toBe(204);
  });

  it('unknown API routes answer 404 with the standard body', async () => {
    const c = await login(USERS.admin);
    const res = await c.get('/does-not-exist');
    expect(res.status).toBe(404);
    expect(res.body).toEqual({ message: 'Resource not found.' });
  });
});

describe('refresh, logout and expiry', () => {
  it('refresh rotates the refresh token; a replayed old token is refused', async () => {
    const c = new Client();
    const loginRes = await c.post('/auth/login', { email: USERS.reception, password: PASSWORD });
    const original = cookieValue(loginRes, 'lab_refresh');
    const first = await c.post('/auth/refresh');
    expect(first.status).toBe(200);
    const rotated = cookieValue(first, 'lab_refresh');
    expect(rotated).toBeTruthy();
    expect(rotated).not.toBe(original);
    const replay = await new Client().agent.post('/api/auth/refresh').set('X-Requested-With', 'XMLHttpRequest').set('Cookie', `lab_refresh=${original}`);
    expect(replay.status).toBe(401);
    expect((await c.post('/auth/refresh')).status).toBe(200); // the legitimate holder continues
  });

  it('an expired access token is refreshed; the session end is absolute', async () => {
    const start = Date.now();
    vi.setSystemTime(start);
    const c = await login(USERS.reception);
    const session = (await c.get('/auth/me')).body;

    vi.setSystemTime(start + 20 * MIN); // access token (15 min) has expired
    expect((await c.get('/cases')).status).toBe(401);
    const refreshed = await c.post('/auth/refresh');
    expect(refreshed.status).toBe(200);
    expect(refreshed.body.expiresAt).toBe(session.expiresAt); // refreshing never extends the session
    expect((await c.get('/cases')).status).toBe(200);

    vi.setSystemTime(start + 481 * MIN); // past the 8-hour session
    expect((await c.get('/cases')).status).toBe(401);
    const late = await c.post('/auth/refresh');
    expect(late.status).toBe(401);
    expect(late.body.message).toMatch(/expired/i);
  });

  it('logout revokes the session: its cookies no longer work anywhere', async () => {
    const c = new Client();
    const loginRes = await c.post('/auth/login', { email: USERS.qc, password: PASSWORD });
    const access = cookieValue(loginRes, 'lab_access');
    expect((await c.post('/auth/logout')).status).toBe(204);
    // Even a copy of the (not yet expired) access token is dead: it belongs to a revoked session.
    expect((await new Client().agent.get('/api/auth/me').set('Cookie', `lab_access=${access}`)).status).toBe(401);
    expect((await c.get('/auth/me')).status).toBe(401);
    const sessions = await prisma.session.findMany({ where: { user: { email: USERS.qc } } });
    expect(sessions.every((s) => s.revokedAt)).toBe(true);
  });

  it('disabling a user ends their session immediately', async () => {
    const admin = await login(USERS.superAdmin);
    const victim = await login(USERS.technician2);
    expect((await victim.get('/auth/me')).status).toBe(200);
    const u = await prisma.user.findUniqueOrThrow({ where: { email: USERS.technician2 } });
    expect((await admin.patch(`/users/${u.id}/status`, { active: false })).status).toBe(200);
    expect((await victim.get('/auth/me')).status).toBe(401);
    expect((await victim.post('/auth/refresh')).status).toBe(401);
    await admin.patch(`/users/${u.id}/status`, { active: true });
  });

  it('permission changes apply to live sessions on the next request', async () => {
    const admin = await login(USERS.superAdmin);
    const reception = await login(USERS.reception);
    expect((await reception.get('/patients')).status).toBe(200);
    const role = (await admin.get('/roles')).body.find((r: { key: string }) => r.key === 'reception');
    const without = role.permissions.filter((p: string) => p !== 'patients.view');
    expect((await admin.put('/roles/reception', { permissions: without })).status).toBe(200);
    expect((await reception.get('/patients/pat_1024')).status).toBe(403);
    expect((await reception.get('/auth/me')).body.permissions).not.toContain('patients.view');
    await admin.put('/roles/reception', { permissions: role.permissions });
  });
});

describe('password reset', () => {
  it('forgot → reset → old password fails, new works, other sessions end', async () => {
    const other = await login(USERS.manager);
    const unknown = await new Client().post('/auth/forgot-password', { email: 'nobody@48hrs.lab' });
    expect(unknown.status).toBe(200);
    expect(unknown.body.devResetUrl).toBeUndefined();

    const res = await new Client().post('/auth/forgot-password', { email: USERS.manager });
    expect(res.status).toBe(200);
    expect(res.body.message).toBe(unknown.body.message);
    const url = new URL(res.body.devResetUrl, 'http://x');
    const token = url.searchParams.get('token')!;
    const stored = await prisma.passwordResetToken.findFirstOrThrow({ where: { user: { email: USERS.manager } } });
    expect(stored.tokenHash).not.toBe(token);

    const mismatch = await new Client().post('/auth/reset-password', { token, email: USERS.manager, password: 'NewPass123', passwordConfirmation: 'Different1' });
    expect(mismatch.status).toBe(422);
    expect(mismatch.body.errors.passwordConfirmation).toBeTruthy();
    const weak = await new Client().post('/auth/reset-password', { token, email: USERS.manager, password: 'short', passwordConfirmation: 'short' });
    expect(weak.status).toBe(422);

    const ok = await new Client().post('/auth/reset-password', { token, email: USERS.manager, password: 'NewPass123', passwordConfirmation: 'NewPass123' });
    expect(ok.status).toBe(200);
    expect((await other.get('/auth/me')).status).toBe(401);
    expect((await new Client().post('/auth/login', { email: USERS.manager, password: PASSWORD })).status).toBe(401);
    expect((await new Client().post('/auth/login', { email: USERS.manager, password: 'NewPass123' })).status).toBe(200);
    const reuse = await new Client().post('/auth/reset-password', { token, email: USERS.manager, password: 'Another123', passwordConfirmation: 'Another123' });
    expect(reuse.status).toBe(422);
  });
});
