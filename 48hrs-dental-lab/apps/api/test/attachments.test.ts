import { readdir } from 'node:fs/promises';
import { beforeAll, describe, expect, it } from 'vitest';
import { storageRoot } from '../src/lib/storage.ts';
import { login, prisma, resetDb, USERS } from './helpers.ts';

const PNG = Buffer.from('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da6300010000050001', 'hex');
const PDF = Buffer.from('%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF');
const STL = Buffer.from('solid cube\nendsolid cube\n');

beforeAll(() => resetDb());

async function files(dir = storageRoot): Promise<string[]> {
  const entries = await readdir(dir, { withFileTypes: true }).catch(() => []);
  const out: string[] = [];
  for (const e of entries) out.push(...(e.isDirectory() ? await files(`${dir}/${e.name}`) : [`${dir}/${e.name}`]));
  return out.filter((f) => !f.includes('/.incoming/'));
}

describe('attachments', () => {
  it('uploads, lists, downloads byte-for-byte and deletes', async () => {
    const reception = await login(USERS.reception);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'in_production' } });
    const res = await reception.agent.post(`/api/cases/${c.id}/attachments`).set('X-Requested-With', 'XMLHttpRequest').field('category', 'photo').attach('file', PNG, 'intraoral photo.png');
    expect(res.status, JSON.stringify(res.body)).toBe(201);
    expect(res.body).toMatchObject({ name: 'intraoral photo.png', extension: 'png', mimeType: 'image/png', size: PNG.length, category: 'photo', uploadedByName: 'Sagal Warsame' });
    expect(res.body).not.toHaveProperty('storageKey');
    const stored = await prisma.caseAttachment.findUniqueOrThrow({ where: { id: res.body.id } });
    expect(stored.storageKey).not.toContain('intraoral'); // user file names never reach the filesystem

    const detail = (await reception.get(`/cases/${c.id}`)).body;
    expect(detail.attachments.map((a: { id: string }) => a.id)).toContain(res.body.id);

    const dl = await reception.agent.get(`/api/cases/${c.id}/attachments/${res.body.id}/download`).buffer(true).parse((r, cb) => {
      const chunks: Buffer[] = [];
      r.on('data', (d: Buffer) => chunks.push(d));
      r.on('end', () => cb(null, Buffer.concat(chunks)));
    });
    expect(dl.status).toBe(200);
    expect(Buffer.compare(dl.body as Buffer, PNG)).toBe(0);
    expect(dl.headers['content-type']).toBe('image/png');
    expect(dl.headers['content-disposition']).toMatch(/attachment; filename="intraoral photo.png"/);
    expect(dl.headers['x-content-type-options']).toBe('nosniff');

    expect((await files()).some((f) => f.endsWith(stored.storageKey))).toBe(true);
    expect((await reception.del(`/cases/${c.id}/attachments/${res.body.id}`)).status).toBe(204);
    expect((await files()).some((f) => f.endsWith(stored.storageKey))).toBe(false);
    expect((await reception.get(`/cases/${c.id}/attachments/${res.body.id}/download`)).status).toBe(404);
  });

  it('accepts PDF and STL, infers the category', async () => {
    const reception = await login(USERS.reception);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'received' } });
    const pdf = await reception.agent.post(`/api/cases/${c.id}/attachments`).set('X-Requested-With', 'XMLHttpRequest').attach('file', PDF, 'rx.pdf');
    expect(pdf.body.category).toBe('prescription');
    const stl = await reception.agent.post(`/api/cases/${c.id}/attachments`).set('X-Requested-With', 'XMLHttpRequest').attach('file', STL, 'upper.stl');
    expect(stl.body.category).toBe('scan');
  });

  it('rejects disallowed types, spoofed content and missing files (422)', async () => {
    const reception = await login(USERS.reception);
    const c = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'received' } });
    const post = () => reception.agent.post(`/api/cases/${c.id}/attachments`).set('X-Requested-With', 'XMLHttpRequest');
    const before = (await files()).length;
    const exe = await post().attach('file', Buffer.from('MZ\x90\x00'), 'tool.exe');
    expect(exe.status).toBe(422);
    const spoofed = await post().attach('file', Buffer.from('<html><script>alert(1)</script></html>'), 'photo.png');
    expect(spoofed.status).toBe(422);
    expect(spoofed.body.errors.file[0]).toMatch(/not accepted|does not match/);
    const fakePdf = await post().attach('file', PNG, 'scan.pdf');
    expect(fakePdf.body.errors.file[0]).toMatch(/does not match/);
    const none = await post().field('category', 'photo');
    expect(none.status).toBe(422);
    const badCategory = await post().field('category', 'selfie').attach('file', PNG, 'a.png');
    expect(badCategory.body.errors).toHaveProperty('category');
    expect((await files()).length).toBe(before); // nothing rejected was kept
    expect((await readdir(`${storageRoot}/.incoming`)).length).toBe(0);
  });

  it('scope and ownership: other clinics 404, deleting someone else\'s file needs files.delete', async () => {
    const client = await login(USERS.client);
    const other = await prisma.dentalCase.findFirstOrThrow({ where: { clinicId: { not: 'cln_smile' } } });
    const denied = await client.agent.post(`/api/cases/${other.id}/attachments`).set('X-Requested-With', 'XMLHttpRequest').attach('file', PNG, 'x.png');
    expect(denied.status).toBe(404);

    const reception = await login(USERS.reception);
    const tech = await login(USERS.technician);
    const own = await prisma.dentalCase.findFirstOrThrow({ where: { technicianId: 'tec_fatima', status: 'in_production' } });
    const up = await reception.agent.post(`/api/cases/${own.id}/attachments`).set('X-Requested-With', 'XMLHttpRequest').attach('file', PNG, 'r.png');
    expect(up.status).toBe(201);
    expect((await tech.del(`/cases/${own.id}/attachments/${up.body.id}`)).status).toBe(403);
    const admin = await login(USERS.admin);
    expect((await admin.del(`/cases/${own.id}/attachments/${up.body.id}`)).status).toBe(204);
  });
});
