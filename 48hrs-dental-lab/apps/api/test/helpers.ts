import request from 'supertest';
import { expect } from 'vitest';
import { createApp } from '../src/app.ts';
import { seedDemo } from '../src/db/seed.ts';
import { hashPassword } from '../src/lib/password.ts';
import { prisma } from '../src/lib/prisma.ts';

export const app = createApp();
export const PASSWORD = process.env.TEST_USER_PASSWORD!;
export { prisma };

let hash: Promise<string> | null = null;

/** Wipes the test database and loads the demo dataset relative to `now`. */
export async function resetDb(now = Date.now()) {
  hash ??= hashPassword(PASSWORD);
  await seedDemo(prisma, { password: PASSWORD, passwordHash: await hash, now, writeFiles: false });
}

/** A browser-like client: keeps cookies and sends the CSRF header on writes. */
export class Client {
  readonly agent = request.agent(app);

  get(path: string) {
    return this.agent.get(`/api${path}`);
  }
  post(path: string, body?: object) {
    return this.agent.post(`/api${path}`).set('X-Requested-With', 'XMLHttpRequest').send(body);
  }
  put(path: string, body?: object) {
    return this.agent.put(`/api${path}`).set('X-Requested-With', 'XMLHttpRequest').send(body);
  }
  patch(path: string, body?: object) {
    return this.agent.patch(`/api${path}`).set('X-Requested-With', 'XMLHttpRequest').send(body);
  }
  del(path: string) {
    return this.agent.delete(`/api${path}`).set('X-Requested-With', 'XMLHttpRequest');
  }
}

export async function login(email: string, password = PASSWORD) {
  const c = new Client();
  const res = await c.post('/auth/login', { email, password });
  expect(res.status, `login ${email}: ${JSON.stringify(res.body)}`).toBe(200);
  return c;
}

/** Demo accounts by role. */
export const USERS = {
  superAdmin: 'khalid@48hrs.lab',
  admin: 'hodan@48hrs.lab',
  manager: 'omar@48hrs.lab',
  reception: 'sagal@48hrs.lab',
  technician: 'fatima@48hrs.lab',
  technician2: 'ahmed@48hrs.lab',
  qc: 'idil@48hrs.lab',
  delivery: 'bashir@48hrs.lab',
  client: 'amina@smiledental.so',
  disabledClient: 'layla@horizondental.so',
} as const;

/** Creates a case at reception (clock starts now) for the Smile clinic. */
export async function createReceivedCase(c: Client, overrides: Record<string, unknown> = {}) {
  const res = await c.post('/cases', {
    patientId: 'pat_1024',
    doctorId: 'doc_amina',
    clinicId: 'cln_smile',
    serviceId: 'svc_zirconia',
    shade: 'A2',
    teeth: [8, 9],
    priority: 'normal',
    instructions: 'Test case',
    receiveNow: true,
    ...overrides,
  });
  expect(res.status, JSON.stringify(res.body)).toBe(201);
  return res.body as { id: string; caseNumber: string; total: number; invoice: { id: string } | null; receivedAt: string; dueAt: string; status: string };
}
