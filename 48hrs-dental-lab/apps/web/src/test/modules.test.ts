/** Module audit: reports filters, directory list behaviour, global search and notifications via the service layer. */
import { describe, expect, it } from 'vitest';
import { ApiError } from '@/services/api/errors';
import { caseService } from '@/services/caseService';
import { clinicService } from '@/services/clinicService';
import { doctorService } from '@/services/doctorService';
import { notificationService } from '@/services/notificationService';
import { patientService } from '@/services/patientService';
import { reportService, searchService } from '@/services/reportService';
import { technicianService } from '@/services/technicianService';
import { invoiceService } from '@/services/paymentService';
import { getDb } from '@/mocks/db';
import { localDay } from '@48hrs/shared/dates';
import { loginAs } from './helpers';

const range = { from: localDay(Date.now() - 95 * 86_400_000), to: localDay(Date.now()) };

describe('reports use the service layer and honour every filter', () => {
  it('each filter narrows the dataset consistently', async () => {
    await loginAs('hodan@48hrs.lab');
    const all = await reportService.run(range);
    expect(all.totals.cases).toBeGreaterThan(40);

    const byTech = await reportService.run({ ...range, technicianId: 'tec_fatima' });
    expect(byTech.totals.cases).toBeLessThan(all.totals.cases);
    expect(byTech.technicians.map((t) => t.name)).toEqual(['Fatima Nur']);

    const byClinic = await reportService.run({ ...range, clinicId: 'cln_banadir' });
    expect(byClinic.clinics.map((k) => k.name)).toEqual(['Banadir Dental Centre']);

    const byDoctor = await reportService.run({ ...range, doctorId: 'doc_amina' });
    expect(byDoctor.totals.cases).toBe(getDb().cases.filter((c) => c.doctorId === 'doc_amina' && c.status !== 'rejected' && localDay(c.receivedAt ?? c.createdAt) >= range.from).length);

    const byStatus = await reportService.run({ ...range, status: 'in_production' });
    expect(byStatus.byStatus.map((s) => s.status)).toEqual(['in_production']);

    const byType = await reportService.run({ ...range, caseType: 'denture' });
    expect(byType.byCaseType.map((t) => t.caseType)).toEqual(['denture']);

    const narrow = await reportService.run({ from: localDay(Date.now() - 6 * 86_400_000), to: range.to });
    expect(narrow.totals.cases).toBeLessThan(all.totals.cases);
    expect(narrow.daily).toHaveLength(7);
  });

  it('rejects an inverted range and requires reports.view', async () => {
    await loginAs('hodan@48hrs.lab');
    await expect(reportService.run({ from: range.to, to: range.from })).rejects.toMatchObject({ status: 422 });
    await loginAs('sagal@48hrs.lab');
    await expect(reportService.run(range)).rejects.toMatchObject({ status: 403 });
  });
});

describe('directory modules: search, filter, sort, paginate, delete guards', () => {
  it('patients', async () => {
    await loginAs('sagal@48hrs.lab');
    const found = await patientService.list({ search: 'PT-1024' });
    expect(found.data.map((p) => p.code)).toEqual(['PT-1024']);
    const byPhone = await patientService.list({ search: getDb().patients[3].phone!.slice(-6) });
    expect(byPhone.data.some((p) => p.id === getDb().patients[3].id)).toBe(true);
    const clinic = await patientService.list({ clinicId: 'cln_aurora', perPage: 100 });
    expect(clinic.data.every((p) => p.clinicId === 'cln_aurora')).toBe(true);
    const sorted = await patientService.list({ sort: 'name', dir: 'asc', perPage: 100 });
    const names = sorted.data.map((p) => p.name);
    expect(names).toEqual([...names].sort((a, b) => (a < b ? -1 : 1)));
    const page2 = await patientService.list({ perPage: 10, page: 2 });
    expect(page2.meta).toMatchObject({ page: 2, perPage: 10, total: getDb().patients.length });
    await expect(patientService.remove('pat_1024')).rejects.toBeInstanceOf(ApiError); // reception lacks patients.delete
  });

  it('doctors and clinics', async () => {
    await loginAs('hodan@48hrs.lab');
    const inactive = await doctorService.list({ status: 'inactive' });
    expect(inactive.data.map((d) => d.name)).toEqual(['Dr. Nasra Ahmed']);
    const smile = await doctorService.list({ clinicId: 'cln_smile' });
    expect(smile.data.every((d) => d.clinicName === 'Smile Dental Clinic')).toBe(true);
    const byCases = await clinicService.list({ sort: 'caseCount', dir: 'desc' });
    const counts = byCases.data.map((k) => k.caseCount);
    expect(counts).toEqual([...counts].sort((a, b) => b - a));
    expect((await clinicService.list({ search: 'aurora' })).data.map((k) => k.name)).toEqual(['Aurora Dental Studio']);
    await expect(doctorService.remove('doc_amina')).rejects.toMatchObject({ status: 422 });
    await expect(clinicService.remove('cln_smile')).rejects.toMatchObject({ status: 422 });
    const k = await clinicService.create({ name: 'Temp Clinic', contactPerson: '', phone: '+252 61 000 1111', email: '', address: '', status: 'active' });
    await clinicService.remove(k.id);
    expect((await clinicService.list({ search: 'Temp Clinic' })).meta.total).toBe(0);
  });

  it('technicians', async () => {
    await loginAs('omar@48hrs.lab');
    const active = await technicianService.list({ active: true, sort: 'activeCases', dir: 'desc' });
    const loads = active.data.map((t) => t.activeCases);
    expect(loads).toEqual([...loads].sort((a, b) => b - a));
    expect((await technicianService.list({ search: 'ceramics' })).data.map((t) => t.name)).toEqual(['Ahmed Hassan']);
  });
});

describe('global search', () => {
  it('finds case ID, patient, doctor, clinic, phone and invoice, each with a detail link', async () => {
    await loginAs('khalid@48hrs.lab');
    const db = getDb();
    const c = db.cases.find((x) => x.status === 'in_production')!;
    const inv = db.invoices[0];
    const expectHit = async (q: string, type: string, href: RegExp) => {
      const hits = await searchService.search(q);
      expect(hits.some((h) => h.type === type && href.test(h.href)), `${q} → ${type}`).toBe(true);
    };
    await expectHit(c.caseNumber, 'case', new RegExp(`^/cases/${c.id}$`));
    await expectHit('Hodan Jama', 'patient', /^\/patients\/pat_/);
    await expectHit('Hibo', 'doctor', /^\/doctors\/doc_hibo$/);
    await expectHit('Banadir', 'clinic', /^\/clinics\/cln_banadir$/);
    await expectHit('700 1004', 'doctor', /^\/doctors\/doc_abdirahman$/);
    await expectHit(inv.invoiceNumber, 'invoice', new RegExp(`^/invoices/${inv.id}$`));
  });

  it('respects scope: a client only finds its own clinic’s cases', async () => {
    await loginAs('amina@smiledental.so');
    const other = getDb().cases.find((c) => c.clinicId !== 'cln_smile')!;
    expect(await searchService.search(other.caseNumber)).toEqual([]);
  });
});

describe('notifications', () => {
  it('tracks unread count, marks one and all read, and links to the related case', async () => {
    await loginAs('sagal@48hrs.lab');
    const first = await notificationService.list({});
    expect(first.unreadCount).toBeGreaterThan(0);
    const unread = first.data.find((n) => !n.readAt)!;
    expect(unread.caseId && unread.caseNumber).toBeTruthy();
    expect(new Date(unread.createdAt).getTime()).toBeLessThanOrEqual(Date.now());
    await notificationService.markRead(unread.id);
    const after = await notificationService.list({});
    expect(after.unreadCount).toBe(first.unreadCount - 1);
    expect(after.data.find((n) => n.id === unread.id)!.readAt).toBeTruthy();
    expect((await notificationService.list({ unreadOnly: true })).data.every((n) => !n.readAt)).toBe(true);
    await notificationService.markAllRead();
    expect((await notificationService.list({})).unreadCount).toBe(0);
    // Linked case opens for this user.
    await expect(caseService.get(unread.caseId!)).resolves.toMatchObject({ id: unread.caseId });
  });

  it('users only ever see their own notifications', async () => {
    await loginAs('fatima@48hrs.lab');
    const mine = await notificationService.list({ perPage: 100 });
    const me = getDb().users.find((u) => u.email === 'fatima@48hrs.lab')!;
    expect(mine.data.every((n) => n.userId === me.id)).toBe(true);
    const someoneElses = getDb().notifications.find((n) => n.userId !== me.id)!;
    await expect(notificationService.markRead(someoneElses.id)).rejects.toMatchObject({ status: 404 });
  });
});

describe('invoice scope', () => {
  it('clients see only their clinic’s invoices', async () => {
    await loginAs('amina@smiledental.so');
    const list = await invoiceService.list({ perPage: 200 });
    expect(list.data.every((i) => i.clinic.id === 'cln_smile')).toBe(true);
    const other = getDb().invoices.find((i) => i.clinicId !== 'cln_smile')!;
    await expect(invoiceService.get(other.id)).rejects.toMatchObject({ status: 404 });
  });
});
