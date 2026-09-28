/**
 * Patients, doctors, clinics and technicians. Clinic-portal users only see
 * their own clinic's records; records with case history cannot be deleted
 * (set them inactive instead) so the audit trail stays intact.
 */
import { average, hoursBetween, relationStats, TECHNICIAN_ACTIVE_STATUSES, TECHNICIAN_FINISHED_STATUSES, technicianWorkload } from '@48hrs/shared/analytics';
import { patientCode } from '@48hrs/shared/case-keys';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import type { clinicSchema, doctorSchema, patientSchema, technicianSchema } from '@48hrs/shared/schemas';
import type {
  CaseStatus,
  Clinic,
  ClinicDetail,
  ClinicListItem,
  Doctor,
  DoctorDetail,
  DoctorListItem,
  Paginated,
  Patient,
  PatientDetail,
  PatientListItem,
  Technician,
  TechnicianDetail,
  TechnicianListItem,
} from '@48hrs/shared/types';
import { OPEN_STATUSES, PRODUCTION_STATUSES } from '@48hrs/shared/workflow';
import type { z } from 'zod';
import { Prisma } from '../generated/prisma/client.ts';
import { forbidden, notFound, throwIfErrors, unprocessable, validation, type FieldErrors } from '../lib/errors.ts';
import { prisma } from '../lib/prisma.ts';
import { can, caseScope, clinicScope } from '../policies/case-policy.ts';
import { logActivity } from '../repositories/activity-repository.ts';
import { caseListInclude, invoiceFigures, toCaseListItem, toClinic, toDoctor, toPatient, toTechnician, type CaseListRow } from '../repositories/mappers.ts';
import { dirSql, inIdOrder, orderedIdPage, sortBy, type Direction } from '../repositories/ordering.ts';
import { nextSequence, SEQUENCES } from '../repositories/sequence-repository.ts';
import type { AuthContext } from '../types/auth.ts';
import { labDay } from '../utils/dates.ts';
import { money } from '../utils/money.ts';
import { paginate, type PageRequest } from '../utils/query.ts';
import { caseSlaService } from './case-sla-service.ts';

type PatientBody = z.output<typeof patientSchema>;
type DoctorBody = z.output<typeof doctorSchema>;
type ClinicBody = z.output<typeof clinicSchema>;
type TechnicianBody = z.output<typeof technicianSchema>;

export interface DirectoryQuery {
  search?: string;
  status?: 'active' | 'inactive';
  clinicId?: string;
  active?: boolean;
  sort?: string;
  dir: Direction;
}

const insensitive = (v: string) => ({ contains: v, mode: 'insensitive' as const });
const equalsInsensitive = (v: string) => ({ equals: v, mode: 'insensitive' as const });
const NONE = '__none__';

/** Detail pages: the visible cases of one patient/doctor/clinic, their totals and the newest ones. */
async function relationView(auth: AuthContext, where: Prisma.DentalCaseWhereInput, recentLimit: number) {
  const now = Date.now();
  const rows = await prisma.dentalCase.findMany({ where: { AND: [caseScope(auth), where] }, include: caseListInclude, orderBy: { createdAt: 'desc' } });
  const sla = await caseSlaService.config(prisma);
  const items = rows.map((r) => toCaseListItem(r, now));
  const invoices = new Map(rows.filter((r) => r.invoice).map((r) => [r.id, invoiceFigures(r.invoice!, now)] as const));
  return { stats: relationStats(items, invoices, now, sla), recentCases: items.slice(0, recentLimit) };
}

async function outstandingByClinic(clinicIds: string[]) {
  if (!clinicIds.length) return new Map<string, number>();
  const rows = await prisma.$queryRaw<{ clinicId: string; outstanding: Prisma.Decimal | null }[]>`
    SELECT "clinicId", SUM(GREATEST(total - "amountPaid", 0)) AS outstanding FROM invoices WHERE "clinicId" = ANY(${clinicIds}) GROUP BY "clinicId"`;
  return new Map(rows.map((r) => [r.clinicId, money(r.outstanding)]));
}

async function caseCountsBy(field: 'doctorId' | 'clinicId', ids: string[]) {
  const rows = ids.length ? await prisma.dentalCase.groupBy({ by: [field, 'status'], where: { [field]: { in: ids } }, _count: { _all: true } }) : [];
  const total = new Map<string, number>();
  const active = new Map<string, number>();
  for (const r of rows as { status: CaseStatus; _count: { _all: number }; doctorId?: string; clinicId?: string }[]) {
    const key = r[field]!;
    total.set(key, (total.get(key) ?? 0) + r._count._all);
    if (OPEN_STATUSES.includes(r.status)) active.set(key, (active.get(key) ?? 0) + r._count._all);
  }
  return { total, active };
}

function checkDateOfBirth(errors: FieldErrors, dob: string | null) {
  if (dob && new Date(`${dob}T00:00:00Z`).getTime() > Date.now()) errors.dateOfBirth = ['Date of birth cannot be in the future.'];
}

/* -------------------------------- Patients ------------------------------- */

export const patientService = {
  async list(auth: AuthContext, q: DirectoryQuery, page: PageRequest): Promise<Paginated<PatientListItem>> {
    const scope = clinicScope(auth);
    const where: Prisma.PatientWhereInput = {
      AND: [
        scope === null ? {} : { clinicId: scope || NONE },
        q.clinicId ? { clinicId: q.clinicId } : {},
        q.search ? { OR: [{ name: insensitive(q.search) }, { code: insensitive(q.search) }, { phone: insensitive(q.search) }, { email: insensitive(q.search) }] } : {},
      ],
    };
    const total = await prisma.patient.count({ where });
    const { skip, take, meta } = paginate(page, total);
    const sort = q.sort ?? 'createdAt';
    let rows;
    if (sort === 'lastCaseAt') {
      const ids = (await prisma.patient.findMany({ where, select: { id: true } })).map((r) => r.id);
      const pageIds = await orderedIdPage(prisma, (list) => Prisma.sql`
        SELECT p.id FROM patients p LEFT JOIN cases c ON c."patientId" = p.id
        WHERE p.id = ANY(${list}) GROUP BY p.id ORDER BY MAX(c."createdAt") ${dirSql(q.dir)} NULLS LAST, p.id`, ids, skip, take);
      rows = inIdOrder(await prisma.patient.findMany({ where: { id: { in: pageIds } }, include: { clinic: { select: { name: true } }, _count: { select: { cases: true } } } }), pageIds);
    } else {
      const orderBy: Prisma.PatientOrderByWithRelationInput[] =
        sort === 'name' ? [{ name: q.dir }] : sort === 'code' ? [{ code: q.dir }] : sort === 'clinic' ? [{ clinic: { name: q.dir } }, { name: 'asc' }] : sort === 'caseCount' ? [{ cases: { _count: q.dir } }, { name: 'asc' }] : [{ createdAt: q.dir }, { code: q.dir }];
      rows = await prisma.patient.findMany({ where, orderBy, skip, take, include: { clinic: { select: { name: true } }, _count: { select: { cases: true } } } });
    }
    const last = await prisma.dentalCase.groupBy({ by: ['patientId'], where: { patientId: { in: rows.map((r) => r.id) } }, _max: { createdAt: true } });
    const lastBy = new Map(last.map((l) => [l.patientId, l._max.createdAt?.toISOString() ?? null]));
    return { data: rows.map((p) => ({ ...toPatient(p), clinicName: p.clinic?.name ?? null, caseCount: p._count.cases, lastCaseAt: lastBy.get(p.id) ?? null })), meta };
  },

  async get(auth: AuthContext, id: string): Promise<PatientDetail> {
    const p = await prisma.patient.findUnique({ where: { id }, include: { clinic: { select: { name: true } }, _count: { select: { cases: true } } } });
    const scope = clinicScope(auth);
    if (!p || (scope !== null && p.clinicId !== scope)) throw notFound();
    const last = await prisma.dentalCase.aggregate({ where: { patientId: p.id }, _max: { createdAt: true } });
    const view = await relationView(auth, { patientId: p.id }, 50);
    return { ...toPatient(p), clinicName: p.clinic?.name ?? null, caseCount: p._count.cases, lastCaseAt: last._max.createdAt?.toISOString() ?? null, ...view };
  },

  async validate(body: PatientBody, selfId?: string) {
    const errors: FieldErrors = {};
    const code = body.code?.trim();
    if (code && (await prisma.patient.findFirst({ where: { code: equalsInsensitive(code), ...(selfId ? { id: { not: selfId } } : {}) } }))) errors.code = ['This patient reference is already in use.'];
    checkDateOfBirth(errors, body.dateOfBirth);
    if (body.clinicId && !(await prisma.clinic.findUnique({ where: { id: body.clinicId } }))) errors.clinicId = ['Select a valid clinic.'];
    throwIfErrors(errors);
  },

  fields(body: PatientBody) {
    return {
      name: body.name,
      phone: body.phone,
      email: body.email,
      gender: body.gender,
      dateOfBirth: body.dateOfBirth ? new Date(`${body.dateOfBirth}T00:00:00Z`) : null,
      clinicId: body.clinicId,
      notes: body.notes,
    };
  },

  async create(auth: AuthContext, body: PatientBody): Promise<Patient> {
    await this.validate(body);
    const p = await prisma.$transaction(async (tx) => {
      const code = body.code?.trim() || patientCode(await nextSequence(tx, SEQUENCES.patient));
      const created = await tx.patient.create({ data: { code, ...this.fields(body) } });
      await logActivity(tx, auth.user, { action: 'patient.create', description: `Added patient ${created.name}`, subjectType: 'patient', subjectId: created.id, subjectLabel: created.code });
      return created;
    });
    return toPatient(p);
  },

  async update(auth: AuthContext, id: string, body: PatientBody): Promise<Patient> {
    const existing = await prisma.patient.findUnique({ where: { id } });
    if (!existing) throw notFound();
    await this.validate(body, id);
    const p = await prisma.$transaction(async (tx) => {
      const updated = await tx.patient.update({ where: { id }, data: { ...this.fields(body), code: body.code?.trim() || existing.code } });
      await logActivity(tx, auth.user, { action: 'patient.update', description: `Updated patient ${updated.name}`, subjectType: 'patient', subjectId: id, subjectLabel: updated.code });
      return updated;
    });
    return toPatient(p);
  },

  async remove(auth: AuthContext, id: string) {
    const p = await prisma.patient.findUnique({ where: { id }, include: { _count: { select: { cases: true } } } });
    if (!p) throw notFound();
    if (p._count.cases) throw unprocessable('This patient has cases and cannot be deleted.');
    await prisma.$transaction(async (tx) => {
      await tx.patient.delete({ where: { id } });
      await logActivity(tx, auth.user, { action: 'patient.delete', description: `Deleted patient ${p.name}`, subjectType: 'patient', subjectLabel: p.code });
    });
  },
};

/* -------------------------------- Doctors -------------------------------- */

export const doctorService = {
  async list(auth: AuthContext, q: DirectoryQuery, page: PageRequest): Promise<Paginated<DoctorListItem>> {
    const scope = clinicScope(auth);
    const rows = await prisma.doctor.findMany({
      where: {
        AND: [
          scope === null ? {} : { clinicId: scope || NONE },
          q.status ? { status: q.status } : {},
          q.clinicId ? { clinicId: q.clinicId } : {},
          q.search ? { OR: [{ name: insensitive(q.search) }, { phone: insensitive(q.search) }, { email: insensitive(q.search) }, { specialty: insensitive(q.search) }] } : {},
        ],
      },
      include: { clinic: { select: { name: true } } },
    });
    const counts = await caseCountsBy('doctorId', rows.map((r) => r.id));
    const items: DoctorListItem[] = rows.map((d) => ({ ...toDoctor(d), clinicName: d.clinic.name, caseCount: counts.total.get(d.id) ?? 0, activeCases: counts.active.get(d.id) ?? 0 }));
    const keys: Record<string, (d: DoctorListItem) => string | number> = { name: (d) => d.name, clinic: (d) => d.clinicName, caseCount: (d) => d.caseCount, activeCases: (d) => d.activeCases, status: (d) => d.status };
    const sorted = sortBy(items, keys[q.sort ?? 'name'] ?? keys.name, q.dir);
    const { skip, take, meta } = paginate(page, sorted.length);
    return { data: sorted.slice(skip, skip + take), meta };
  },

  async get(auth: AuthContext, id: string): Promise<DoctorDetail> {
    const d = await prisma.doctor.findUnique({ where: { id }, include: { clinic: true } });
    if (!d) throw notFound();
    const counts = await caseCountsBy('doctorId', [d.id]);
    const view = await relationView(auth, { doctorId: d.id }, 8);
    return { ...toDoctor(d), clinicName: d.clinic.name, caseCount: counts.total.get(d.id) ?? 0, activeCases: counts.active.get(d.id) ?? 0, clinic: toClinic(d.clinic), ...view };
  },

  async validate(body: DoctorBody) {
    if (!(await prisma.clinic.findUnique({ where: { id: body.clinicId } }))) throw validation({ clinicId: ['Select a valid clinic.'] });
  },

  async create(auth: AuthContext, body: DoctorBody): Promise<Doctor> {
    await this.validate(body);
    const d = await prisma.$transaction(async (tx) => {
      const created = await tx.doctor.create({ data: body });
      await logActivity(tx, auth.user, { action: 'doctor.create', description: `Added ${created.name}`, subjectType: 'doctor', subjectId: created.id, subjectLabel: created.name });
      return created;
    });
    return toDoctor(d);
  },

  async update(auth: AuthContext, id: string, body: DoctorBody): Promise<Doctor> {
    if (!(await prisma.doctor.findUnique({ where: { id } }))) throw notFound();
    await this.validate(body);
    const d = await prisma.$transaction(async (tx) => {
      const updated = await tx.doctor.update({ where: { id }, data: body });
      await logActivity(tx, auth.user, { action: 'doctor.update', description: `Updated ${updated.name}`, subjectType: 'doctor', subjectId: id, subjectLabel: updated.name });
      return updated;
    });
    return toDoctor(d);
  },

  async remove(auth: AuthContext, id: string) {
    const d = await prisma.doctor.findUnique({ where: { id }, include: { _count: { select: { cases: true } } } });
    if (!d) throw notFound();
    if (d._count.cases) throw unprocessable('This doctor has cases. Set them to inactive instead.');
    await prisma.$transaction(async (tx) => {
      await tx.doctor.delete({ where: { id } });
      await logActivity(tx, auth.user, { action: 'doctor.delete', description: `Deleted ${d.name}`, subjectType: 'doctor', subjectLabel: d.name });
    });
  },
};

/* -------------------------------- Clinics -------------------------------- */

async function clinicItems(rows: Prisma.ClinicGetPayload<{ include: { _count: { select: { doctors: true } } } }>[]): Promise<ClinicListItem[]> {
  const ids = rows.map((r) => r.id);
  const [counts, outstanding] = await Promise.all([caseCountsBy('clinicId', ids), outstandingByClinic(ids)]);
  return rows.map((k) => ({ ...toClinic(k), doctorCount: k._count.doctors, caseCount: counts.total.get(k.id) ?? 0, activeCases: counts.active.get(k.id) ?? 0, outstanding: outstanding.get(k.id) ?? 0 }));
}

export const clinicService = {
  async list(auth: AuthContext, q: DirectoryQuery, page: PageRequest): Promise<Paginated<ClinicListItem>> {
    const scope = clinicScope(auth);
    const rows = await prisma.clinic.findMany({
      where: {
        AND: [
          scope === null ? {} : { id: scope || NONE },
          q.status ? { status: q.status } : {},
          q.search ? { OR: [{ name: insensitive(q.search) }, { contactPerson: insensitive(q.search) }, { phone: insensitive(q.search) }, { email: insensitive(q.search) }, { address: insensitive(q.search) }] } : {},
        ],
      },
      include: { _count: { select: { doctors: true } } },
    });
    const items = await clinicItems(rows);
    const keys: Record<string, (k: ClinicListItem) => string | number> = { name: (k) => k.name, caseCount: (k) => k.caseCount, activeCases: (k) => k.activeCases, outstanding: (k) => k.outstanding, status: (k) => k.status };
    const sorted = sortBy(items, keys[q.sort ?? 'name'] ?? keys.name, q.dir);
    const { skip, take, meta } = paginate(page, sorted.length);
    return { data: sorted.slice(skip, skip + take), meta };
  },

  async get(auth: AuthContext, id: string): Promise<ClinicDetail> {
    const k = await prisma.clinic.findUnique({ where: { id }, include: { _count: { select: { doctors: true } }, doctors: { orderBy: { name: 'asc' } } } });
    if (!k) throw notFound();
    const [item] = await clinicItems([k]);
    const view = await relationView(auth, { clinicId: k.id }, 8);
    return { ...item, doctors: k.doctors.map(toDoctor), ...view };
  },

  async assertUniqueName(name: string, selfId?: string) {
    if (await prisma.clinic.findFirst({ where: { name: equalsInsensitive(name), ...(selfId ? { id: { not: selfId } } : {}) } })) throw validation({ name: ['A clinic with this name already exists.'] });
  },

  async create(auth: AuthContext, body: ClinicBody): Promise<Clinic> {
    await this.assertUniqueName(body.name);
    const k = await prisma.$transaction(async (tx) => {
      const created = await tx.clinic.create({ data: body });
      await logActivity(tx, auth.user, { action: 'clinic.create', description: `Added clinic ${created.name}`, subjectType: 'clinic', subjectId: created.id, subjectLabel: created.name });
      return created;
    });
    return toClinic(k);
  },

  async update(auth: AuthContext, id: string, body: ClinicBody): Promise<Clinic> {
    if (!(await prisma.clinic.findUnique({ where: { id } }))) throw notFound();
    await this.assertUniqueName(body.name, id);
    const k = await prisma.$transaction(async (tx) => {
      const updated = await tx.clinic.update({ where: { id }, data: body });
      await logActivity(tx, auth.user, { action: 'clinic.update', description: `Updated clinic ${updated.name}`, subjectType: 'clinic', subjectId: id, subjectLabel: updated.name });
      return updated;
    });
    return toClinic(k);
  },

  async remove(auth: AuthContext, id: string) {
    const k = await prisma.clinic.findUnique({ where: { id }, include: { _count: { select: { cases: true, doctors: true } } } });
    if (!k) throw notFound();
    if (k._count.cases || k._count.doctors) throw unprocessable('This clinic has doctors or cases. Set it to inactive instead.');
    await prisma.$transaction(async (tx) => {
      await tx.clinic.delete({ where: { id } });
      await logActivity(tx, auth.user, { action: 'clinic.delete', description: `Deleted clinic ${k.name}`, subjectType: 'clinic', subjectLabel: k.name });
    });
  },
};

/* ------------------------------ Technicians ------------------------------ */

const workloadSelect = { technicianId: true, status: true, receivedAt: true, dueAt: true, deliveredAt: true } satisfies Prisma.DentalCaseSelect;

function slaFields(c: Prisma.DentalCaseGetPayload<{ select: typeof workloadSelect }>) {
  return { status: c.status, receivedAt: c.receivedAt?.toISOString() ?? null, dueAt: c.dueAt?.toISOString() ?? null, deliveredAt: c.deliveredAt?.toISOString() ?? null };
}

export const technicianService = {
  async list(q: DirectoryQuery, page: PageRequest): Promise<Paginated<TechnicianListItem>> {
    const now = Date.now();
    const rows = await prisma.technician.findMany({
      where: {
        AND: [
          q.active === undefined ? {} : { active: q.active },
          q.search ? { OR: [{ name: insensitive(q.search) }, { email: insensitive(q.search) }, { phone: insensitive(q.search) }, { specialty: insensitive(q.search) }] } : {},
        ],
      },
    });
    const [cases, sla] = await Promise.all([
      prisma.dentalCase.findMany({ where: { technicianId: { in: rows.map((r) => r.id) } }, select: workloadSelect }),
      caseSlaService.config(prisma),
    ]);
    const items: TechnicianListItem[] = rows.map((t) => ({ ...toTechnician(t), ...technicianWorkload(cases.filter((c) => c.technicianId === t.id).map(slaFields), now, sla, labDay) }));
    const keys: Record<string, (t: TechnicianListItem) => string | number | null> = {
      name: (t) => t.name,
      activeCases: (t) => t.activeCases,
      completedCases: (t) => t.completedCases,
      overdue: (t) => t.overdue,
      onTimeRate: (t) => t.onTimeRate,
    };
    const sorted = sortBy(items, keys[q.sort ?? 'name'] ?? keys.name, q.dir);
    const { skip, take, meta } = paginate(page, sorted.length);
    return { data: sorted.slice(skip, skip + take), meta };
  },

  /** Technicians may always open their own profile; anyone else needs technicians.view. */
  async get(auth: AuthContext, id: string): Promise<TechnicianDetail> {
    if (auth.user.technicianId !== id && !can(auth, PERMISSIONS.TECHNICIANS_VIEW)) throw forbidden();
    const t = await prisma.technician.findUnique({ where: { id } });
    if (!t) throw notFound();
    const now = Date.now();
    const sla = await caseSlaService.config(prisma);
    const rows: CaseListRow[] = await prisma.dentalCase.findMany({ where: { technicianId: id }, include: caseListInclude });
    const items = rows.map((r) => toCaseListItem(r, now));
    const failures = await prisma.qualityCheck.count({ where: { result: 'failed', case: { technicianId: id } } });
    return {
      ...toTechnician(t),
      ...technicianWorkload(items, now, sla, labDay),
      qcPending: items.filter((c) => c.status === 'quality_control').length,
      qcFailures: failures,
      avgProductionHours: average(items.map((c) => hoursBetween(c.productionStartedAt, c.productionCompletedAt)).filter((x): x is number => x !== null)),
      activeCaseList: items.filter((c) => TECHNICIAN_ACTIVE_STATUSES.includes(c.status)).sort((a, b) => (a.dueAt ?? '').localeCompare(b.dueAt ?? '')),
      recentCompleted: items
        .filter((c) => TECHNICIAN_FINISHED_STATUSES.includes(c.status))
        .sort((a, b) => (b.readyAt ?? '').localeCompare(a.readyAt ?? ''))
        .slice(0, 10),
    };
  },

  async assertUniqueEmail(email: string, selfId?: string) {
    if (await prisma.technician.findFirst({ where: { email: equalsInsensitive(email), ...(selfId ? { id: { not: selfId } } : {}) } })) throw validation({ email: ['Another technician uses this email.'] });
  },

  async create(auth: AuthContext, body: TechnicianBody): Promise<Technician> {
    await this.assertUniqueEmail(body.email);
    const t = await prisma.$transaction(async (tx) => {
      // A technician login with the same e-mail is linked automatically.
      const linked = await tx.user.findFirst({ where: { email: body.email, roleKey: 'technician', technician: null } });
      const created = await tx.technician.create({ data: { ...body, userId: linked?.id ?? null } });
      await logActivity(tx, auth.user, { action: 'technician.create', description: `Added technician ${created.name}`, subjectType: 'technician', subjectId: created.id, subjectLabel: created.name });
      return created;
    });
    return toTechnician(t);
  },

  async update(auth: AuthContext, id: string, body: TechnicianBody): Promise<Technician> {
    const t = await prisma.technician.findUnique({ where: { id } });
    if (!t) throw notFound();
    await this.assertUniqueEmail(body.email, id);
    if (!body.active && (await prisma.dentalCase.count({ where: { technicianId: id, status: { in: PRODUCTION_STATUSES } } }))) {
      throw unprocessable(`${t.name} still has cases in production. Reassign them before deactivating.`);
    }
    const updated = await prisma.$transaction(async (tx) => {
      const u = await tx.technician.update({ where: { id }, data: body });
      await logActivity(tx, auth.user, { action: 'technician.update', description: `Updated technician ${u.name}`, subjectType: 'technician', subjectId: id, subjectLabel: u.name });
      return u;
    });
    return toTechnician(updated);
  },

  async remove(auth: AuthContext, id: string) {
    const t = await prisma.technician.findUnique({ where: { id }, include: { _count: { select: { cases: true, assignments: true } } } });
    if (!t) throw notFound();
    if (t._count.cases || t._count.assignments) throw unprocessable(`${t.name} has case history and cannot be deleted. Set the technician to inactive instead.`);
    await prisma.$transaction(async (tx) => {
      await tx.technician.delete({ where: { id } });
      await logActivity(tx, auth.user, { action: 'technician.delete', description: `Deleted technician ${t.name}`, subjectType: 'technician', subjectLabel: t.name });
    });
  },
};

