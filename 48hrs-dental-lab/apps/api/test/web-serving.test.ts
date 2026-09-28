/**
 * Single-origin deployment (WEB_DIST_DIR): the API also serves the built SPA.
 * Only the web build is public; uploaded case files are never reachable by
 * path, even when the storage key is known.
 */
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { beforeAll, describe, expect, it } from 'vitest';

const web = mkdtempSync(join(tmpdir(), '48hrs-web-'));
mkdirSync(join(web, 'assets'));
writeFileSync(join(web, 'index.html'), '<!doctype html><title>48HRS</title><div id="root"></div>');
writeFileSync(join(web, 'assets', 'app.js'), 'console.info("app")');
process.env.WEB_DIST_DIR = web;
const { default: request } = await import('supertest');
const { app, login, prisma, resetDb, USERS } = await import('./helpers.ts');

beforeAll(() => resetDb());

const PNG = Buffer.from('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da6300010000050001', 'hex');

describe('serving the web app from the API origin', () => {
  it('serves the SPA with a strict CSP, falls back to index.html for app routes, keeps /api JSON', async () => {
    const page = await request(app).get('/cases/some-id').set('Accept', 'text/html');
    expect(page.status).toBe(200);
    expect(page.text).toContain('<div id="root">');
    expect(page.headers['content-security-policy']).toMatch(/default-src 'self'/);
    expect(page.headers['content-security-policy']).toMatch(/frame-ancestors 'none'/);
    expect(page.headers['cache-control']).toBe('no-cache');
    const asset = await request(app).get('/assets/app.js');
    expect(asset.status).toBe(200);
    expect(asset.headers['cache-control']).toMatch(/immutable/);
    // Unknown API paths never fall through to the SPA: 401 without a session (routes are not revealed), 404 JSON with one.
    const anon = await request(app).get('/api/nope').set('Accept', 'text/html');
    expect(anon.status).toBe(401);
    expect(anon.headers['content-type']).toMatch(/json/);
    const signedIn = await (await login(USERS.admin)).agent.get('/api/nope').set('Accept', 'text/html');
    expect(signedIn.status).toBe(404);
    expect(signedIn.body).toEqual({ message: 'Resource not found.' });
  });

  it('a known storage key never exposes the uploaded file', async () => {
    const reception = await login(USERS.reception);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production' } });
    const up = await reception.agent.post(`/api/cases/${c.id}/attachments`).set('X-Requested-With', 'XMLHttpRequest').attach('file', PNG, 'secret.png');
    expect(up.status).toBe(201);
    const { storageKey } = await prisma.caseAttachment.findUniqueOrThrow({ where: { id: up.body.id } });
    for (const path of [`/${storageKey}`, `/uploads/${storageKey}`, `/storage/uploads/${storageKey}`, `/api/uploads/${storageKey}`, `/../storage/uploads/${storageKey}`, `/%2e%2e/storage/uploads/${storageKey}`]) {
      for (const accept of ['*/*', 'image/png', 'text/html']) {
        const res = await request(app).get(path).set('Accept', accept).buffer(true);
        const body = Buffer.isBuffer(res.body) ? res.body : Buffer.from(res.text ?? '');
        expect(body.includes(PNG.subarray(0, 16)), `${path} (${accept}) leaked the file`).toBe(false);
        expect(res.headers['content-type'] ?? '', path).not.toMatch(/image\/png/);
      }
    }
  });
});
