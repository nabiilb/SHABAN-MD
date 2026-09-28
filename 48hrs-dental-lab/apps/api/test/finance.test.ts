import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createReceivedCase, login, prisma, resetDb, USERS } from './helpers.ts';

const DAY = 86_400_000;

beforeEach(() => resetDb());
afterEach(() => vi.useRealTimers());

describe('payments and invoice status', () => {
  it('unpaid → partial → paid, with remaining = total − paid computed by the server', async () => {
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception); // 2 × $20 = $40
    const invoiceId = c.invoice!.id;
    let inv = (await reception.get(`/invoices/${invoiceId}`)).body;
    expect(inv).toMatchObject({ total: 40, paid: 0, remaining: 40, status: 'unpaid' });
    expect(inv.lineItems[0]).toMatchObject({ quantity: 2, unitPrice: 20, amount: 40 });

    inv = (await reception.post('/payments', { invoiceId, amount: 15, method: 'cash' })).body;
    expect(inv).toMatchObject({ paid: 15, remaining: 25, status: 'partial' });
    inv = (await reception.post('/payments', { invoiceId, amount: 25, method: 'mobile_money', reference: 'MM-7' })).body;
    expect(inv).toMatchObject({ paid: 40, remaining: 0, status: 'paid' });
    expect(inv.payments).toHaveLength(2);
    expect(inv.payments[0]).toMatchObject({ amount: 25, method: 'mobile_money', reference: 'MM-7', receivedByName: 'Sagal Warsame' });

    const row = await prisma.invoice.findUniqueOrThrow({ where: { id: invoiceId }, include: { payments: true } });
    expect(row.amountPaid.toNumber()).toBe(row.payments.reduce((s, p) => s + p.amount.toNumber(), 0));
    expect((await reception.get(`/cases/${c.id}`)).body.paymentStatus).toBe('paid');
  });

  it('overdue once the due date passes with money owed', async () => {
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception);
    vi.useFakeTimers({ toFake: ['Date'], now: Date.now() + 15 * DAY });
    const later = await login(USERS.reception);
    expect((await later.get(`/invoices/${c.invoice!.id}`)).body.status).toBe('overdue');
    const overdue = (await later.get('/invoices?status=overdue&perPage=200')).body.data as { id: string; status: string }[];
    expect(overdue.some((i) => i.id === c.invoice!.id)).toBe(true);
    expect(overdue.every((i) => i.status === 'overdue')).toBe(true);
  });

  it('rejects invalid payments (422) without changing the balance', async () => {
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception);
    const invoiceId = c.invoice!.id;
    const cases = [
      [{ invoiceId, amount: 0, method: 'cash' }, 'amount'],
      [{ invoiceId, amount: -5, method: 'cash' }, 'amount'],
      [{ invoiceId, amount: 41, method: 'cash' }, 'amount'],
      [{ invoiceId, amount: 'ten', method: 'cash' }, 'amount'],
      [{ invoiceId, amount: 10, method: 'bank_transfer' }, 'reference'],
      [{ invoiceId, amount: 10, method: 'bitcoin' }, 'method'],
      [{ invoiceId, amount: 10, method: 'cash', paidAt: new Date(Date.now() + 2 * DAY).toISOString() }, 'paidAt'],
      [{ amount: 10, method: 'cash' }, 'invoiceId'],
    ] as const;
    for (const [body, field] of cases) {
      const res = await reception.post('/payments', body);
      expect(res.status, JSON.stringify(body)).toBe(422);
      expect(res.body.errors, JSON.stringify(body)).toHaveProperty(field);
    }
    expect((await reception.post('/payments', { invoiceId: 'inv_missing', amount: 1, method: 'cash' })).status).toBe(404);
    expect((await prisma.invoice.findUniqueOrThrow({ where: { id: invoiceId } })).amountPaid.toNumber()).toBe(0);
  });

  it('concurrent payments can never exceed the total (row lock)', async () => {
    const reception = await login(USERS.reception);
    const admin = await login(USERS.admin);
    const c = await createReceivedCase(reception);
    const invoiceId = c.invoice!.id;
    const results = await Promise.all([
      reception.post('/payments', { invoiceId, amount: 30, method: 'cash' }),
      admin.post('/payments', { invoiceId, amount: 30, method: 'cash' }),
    ]);
    expect(results.map((r) => r.status).sort()).toEqual([201, 422]);
    const row = await prisma.invoice.findUniqueOrThrow({ where: { id: invoiceId } });
    expect(row.amountPaid.toNumber()).toBe(30);
  });

  it('only payments.record may take money; clients only see their clinic', async () => {
    const tech = await login(USERS.technician);
    const inv = await prisma.invoice.findFirstOrThrow({ where: { clinicId: 'cln_smile' } });
    expect((await tech.post('/payments', { invoiceId: inv.id, amount: 1, method: 'cash' })).status).toBe(403);
    expect((await tech.get('/invoices')).status).toBe(403);
    const client = await login(USERS.client);
    const mine = (await client.get('/invoices?perPage=200')).body.data as { clinic: { id: string } }[];
    expect(mine.length).toBe(await prisma.invoice.count({ where: { clinicId: 'cln_smile' } }));
    expect(mine.every((i) => i.clinic.id === 'cln_smile')).toBe(true);
    const other = await prisma.invoice.findFirstOrThrow({ where: { clinicId: { not: 'cln_smile' } } });
    expect((await client.get(`/invoices/${other.id}`)).status).toBe(404);
    expect((await client.get('/payments')).status).toBe(403);
  });

  it('invoices are listed, filtered, searched and sorted by the balance', async () => {
    const admin = await login(USERS.admin);
    const byRemaining = (await admin.get('/invoices?sort=remaining&dir=desc&perPage=200')).body.data as { remaining: number }[];
    expect(byRemaining.map((i) => i.remaining)).toEqual([...byRemaining.map((i) => i.remaining)].sort((a, b) => b - a));
    const paid = (await admin.get('/invoices?status=paid&perPage=200')).body.data as { status: string }[];
    expect(paid.length).toBeGreaterThan(0);
    expect(paid.every((i) => i.status === 'paid')).toBe(true);
    const one = await prisma.invoice.findFirstOrThrow();
    expect((await admin.get(`/invoices?search=${one.invoiceNumber}`)).body.data.map((i: { id: string }) => i.id)).toEqual([one.id]);
    expect((await admin.get(`/invoices/${one.invoiceNumber}`)).body.id).toBe(one.id);
    const payments = (await admin.get('/payments?method=cash&perPage=200')).body;
    expect(payments.data.every((p: { method: string }) => p.method === 'cash')).toBe(true);
    expect(payments.data[0]).toHaveProperty('caseNumber');
  });

  it('POST /invoices issues a missing invoice once', async () => {
    const reception = await login(USERS.reception);
    const c = await createReceivedCase(reception);
    await prisma.invoice.delete({ where: { caseId: c.id } });
    const res = await reception.post('/invoices', { caseId: c.id });
    expect(res.status).toBe(201);
    expect(res.body).toMatchObject({ caseId: c.id, total: 40, status: 'unpaid' });
    expect((await reception.post('/invoices', { caseId: c.id })).status).toBe(422);
    const submitted = await prisma.dentalCase.findFirstOrThrow({ where: { status: 'submitted' } });
    expect((await reception.post('/invoices', { caseId: submitted.id })).body.errors.caseId[0]).toMatch(/received/);
  });
});
