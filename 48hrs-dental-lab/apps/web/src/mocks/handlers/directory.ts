import { average, hoursBetween, TECHNICIAN_ACTIVE_STATUSES, TECHNICIAN_FINISHED_STATUSES, technicianWorkload } from '@48hrs/shared/analytics';
import { PERMISSIONS, hasPermission } from '@48hrs/shared/permissions';
import { PRODUCTION_STATUSES, STATUS_META } from '@48hrs/shared/workflow';
import { ApiError } from '@/services/api/errors';
import type {
  ClinicDetail,
  ClinicListItem,
  ClinicPayload,
  DoctorDetail,
  DoctorListItem,
  DoctorPayload,
  PatientDetail,
  PatientListItem,
  PatientPayload,
  TechnicianDetail,
  TechnicianListItem,
  TechnicianPayload,
} from '@48hrs/shared/types';
import type { Clinic, Doctor, LabCase, Patient, Technician } from '@48hrs/shared/types';
import { patientCode } from '@48hrs/shared/case-keys';
import { localDay, toIso } from '@48hrs/shared/dates';
import { authenticate, authorize } from '../auth-context';
import { nextId, type MockDatabase } from '../db';
import { invoiceView, logActivity, recentCases, relationStats, slaConfig, toListItem, visibleCases } from '../domain';
import {
  EMAIL_RE,
  includesText,
  paginate,
  qBool,
  qStr,
  requireFields,
  route,
  sortItems,
  validationError,
  type AuthedContext,
  type FieldErrors,
  notFound,
} from '../router';


function checkEmail(errors: FieldErrors, email: string | undefined, required = false) {
  if (!email?.trim()) {
    if (required) errors.email = ['Email is required.'];
    return;
  }
  if (!EMAIL_RE.test(email.trim())) errors.email = ['Enter a valid email address.'];
}

function checkPhone(errors: FieldErrors, phone: string | undefined, required = false) {
  if (!phone?.trim()) {
    if (required) errors.phone = ['Phone is required.'];
    return;
  }
  if (!/^[+\d][\d\s()-]{5,}$/.test(phone.trim())) errors.phone = ['Enter a valid phone number.'];
}

function throwIf(errors: FieldErrors) {
  if (Object.keys(errors).length) throw validationError(errors);
}

/** Client users only see their own clinic's directory records. */
function clinicScope(ctx: AuthedContext) {
  return hasPermission(ctx.permissions, PERMISSIONS.CASES_VIEW_ALL) ? null : ctx.user.clinicId ?? null;
}

/* -------------------------------- Patients ------------------------------ */

function patientItem(db: MockDatabase, p: Patient): PatientListItem {
  const cases = db.cases.filter((c) => c.patientId === p.id);
  const last = cases.map((c) => c.createdAt).sort().pop() ?? null;
  return { ...p, clinicName: db.clinics.find((k) => k.id === p.clinicId)?.name ?? null, caseCount: cases.length, lastCaseAt: last };
}

route('GET', '/patients', (raw) => {
  const ctx = authenticate(raw);
  // Clinic-portal users may look up their own clinic's patients when submitting a case.
  authorize(ctx, [PERMISSIONS.PATIENTS_VIEW, PERMISSIONS.CASES_SUBMIT], 'any');
  const q = raw.query;
  const scope = clinicScope(ctx);
  const items = ctx.db.patients
    .filter((p) => !scope || p.clinicId === scope)
    .filter((p) => !qStr(q, 'clinicId') || p.clinicId === qStr(q, 'clinicId'))
    .filter((p) => includesText([p.name, p.code, p.phone, p.email], qStr(q, 'search')))
    .map((p) => patientItem(ctx.db, p));
  return paginate(
    sortItems(items, q, { name: (p) => p.name, code: (p) => p.code, clinic: (p) => p.clinicName, caseCount: (p) => p.caseCount, createdAt: (p) => p.createdAt, lastCaseAt: (p) => p.lastCaseAt }, 'createdAt'),
    q,
  );
});

route('GET', '/patients/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.PATIENTS_VIEW);
  const p = ctx.db.patients.find((x) => x.id === raw.params.id);
  const scope = clinicScope(ctx);
  if (!p || (scope && p.clinicId !== scope)) throw notFound();
  const cases = visibleCases(ctx).filter((c) => c.patientId === p.id);
  const detail: PatientDetail = { ...patientItem(ctx.db, p), stats: relationStats(ctx.db, cases, ctx.now), recentCases: recentCases(ctx.db, cases, ctx.now, 50) };
  return detail;
});

function validatePatient(db: MockDatabase, body: Partial<PatientPayload>, selfId?: string) {
  requireFields(body as Record<string, unknown>, { name: 'Patient name' });
  const errors: FieldErrors = {};
  checkEmail(errors, body.email);
  checkPhone(errors, body.phone);
  if (body.code && db.patients.some((p) => p.id !== selfId && p.code.toLowerCase() === body.code!.trim().toLowerCase())) errors.code = ['This patient reference is already in use.'];
  if (body.dateOfBirth && new Date(body.dateOfBirth).getTime() > Date.now()) errors.dateOfBirth = ['Date of birth cannot be in the future.'];
  if (body.clinicId && !db.clinics.some((k) => k.id === body.clinicId)) errors.clinicId = ['Select a valid clinic.'];
  throwIf(errors);
}

route('POST', '/patients', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.PATIENTS_CREATE);
  const body = (raw.body ?? {}) as PatientPayload;
  validatePatient(ctx.db, body);
  ctx.db.counters.patient += 1;
  const p: Patient = {
    id: nextId('pat'),
    code: body.code?.trim() || patientCode(ctx.db.counters.patient),
    name: body.name.trim(),
    phone: body.phone?.trim() ?? '',
    email: body.email?.trim() ?? '',
    gender: body.gender ?? null,
    dateOfBirth: body.dateOfBirth || null,
    clinicId: body.clinicId || null,
    notes: body.notes?.trim() ?? '',
    createdAt: toIso(ctx.now),
  };
  ctx.db.patients.push(p);
  logActivity(ctx.db, ctx.user, { action: 'patient.create', description: `Added patient ${p.name}`, subjectType: 'patient', subjectId: p.id, subjectLabel: p.code }, ctx.now);
  return p;
});

route('PUT', '/patients/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.PATIENTS_EDIT);
  const p = ctx.db.patients.find((x) => x.id === raw.params.id);
  if (!p) throw notFound();
  const body = (raw.body ?? {}) as PatientPayload;
  validatePatient(ctx.db, body, p.id);
  Object.assign(p, {
    name: body.name.trim(),
    code: body.code?.trim() || p.code,
    phone: body.phone?.trim() ?? '',
    email: body.email?.trim() ?? '',
    gender: body.gender ?? null,
    dateOfBirth: body.dateOfBirth || null,
    clinicId: body.clinicId || null,
    notes: body.notes?.trim() ?? '',
  });
  logActivity(ctx.db, ctx.user, { action: 'patient.update', description: `Updated patient ${p.name}`, subjectType: 'patient', subjectId: p.id, subjectLabel: p.code }, ctx.now);
  return p;
});

route('DELETE', '/patients/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.PATIENTS_DELETE);
  const p = ctx.db.patients.find((x) => x.id === raw.params.id);
  if (!p) throw notFound();
  if (ctx.db.cases.some((c) => c.patientId === p.id)) throw new ApiError(422, 'This patient has cases and cannot be deleted.');
  ctx.db.patients = ctx.db.patients.filter((x) => x !== p);
  logActivity(ctx.db, ctx.user, { action: 'patient.delete', description: `Deleted patient ${p.name}`, subjectType: 'patient', subjectLabel: p.code }, ctx.now);
  return null;
});

/* -------------------------------- Doctors ------------------------------- */

function doctorItem(db: MockDatabase, d: Doctor): DoctorListItem {
  const cases = db.cases.filter((c) => c.doctorId === d.id);
  return { ...d, clinicName: db.clinics.find((k) => k.id === d.clinicId)?.name ?? '—', caseCount: cases.length, activeCases: cases.filter((c) => STATUS_META[c.status].open).length };
}

route('GET', '/doctors', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, [PERMISSIONS.DOCTORS_VIEW, PERMISSIONS.CASES_SUBMIT], 'any');
  const q = raw.query;
  const scope = clinicScope(ctx);
  const items = ctx.db.doctors
    .filter((d) => !scope || d.clinicId === scope)
    .filter((d) => !qStr(q, 'status') || d.status === qStr(q, 'status'))
    .filter((d) => !qStr(q, 'clinicId') || d.clinicId === qStr(q, 'clinicId'))
    .filter((d) => includesText([d.name, d.phone, d.email, d.specialty], qStr(q, 'search')))
    .map((d) => doctorItem(ctx.db, d));
  return paginate(sortItems(items, q, { name: (d) => d.name, clinic: (d) => d.clinicName, caseCount: (d) => d.caseCount, activeCases: (d) => d.activeCases, status: (d) => d.status }, 'name'), q);
});

route('GET', '/doctors/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.DOCTORS_VIEW);
  const d = ctx.db.doctors.find((x) => x.id === raw.params.id);
  if (!d) throw notFound();
  const cases = visibleCases(ctx).filter((c) => c.doctorId === d.id);
  const detail: DoctorDetail = { ...doctorItem(ctx.db, d), clinic: ctx.db.clinics.find((k) => k.id === d.clinicId)!, stats: relationStats(ctx.db, cases, ctx.now), recentCases: recentCases(ctx.db, cases, ctx.now) };
  return detail;
});

function validateDoctor(db: MockDatabase, body: Partial<DoctorPayload>) {
  requireFields(body as Record<string, unknown>, { name: 'Name', clinicId: 'Clinic', phone: 'Phone' });
  const errors: FieldErrors = {};
  checkEmail(errors, body.email);
  checkPhone(errors, body.phone, true);
  if (!db.clinics.some((k) => k.id === body.clinicId)) errors.clinicId = ['Select a valid clinic.'];
  throwIf(errors);
}

route('POST', '/doctors', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.DOCTORS_CREATE);
  const body = (raw.body ?? {}) as DoctorPayload;
  validateDoctor(ctx.db, body);
  const d: Doctor = { id: nextId('doc'), name: body.name.trim(), clinicId: body.clinicId, phone: body.phone.trim(), email: body.email?.trim() ?? '', specialty: body.specialty?.trim() ?? '', status: body.status ?? 'active', createdAt: toIso(ctx.now) };
  ctx.db.doctors.push(d);
  logActivity(ctx.db, ctx.user, { action: 'doctor.create', description: `Added ${d.name}`, subjectType: 'doctor', subjectId: d.id, subjectLabel: d.name }, ctx.now);
  return d;
});

route('PUT', '/doctors/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.DOCTORS_EDIT);
  const d = ctx.db.doctors.find((x) => x.id === raw.params.id);
  if (!d) throw notFound();
  const body = (raw.body ?? {}) as DoctorPayload;
  validateDoctor(ctx.db, body);
  Object.assign(d, { name: body.name.trim(), clinicId: body.clinicId, phone: body.phone.trim(), email: body.email?.trim() ?? '', specialty: body.specialty?.trim() ?? '', status: body.status });
  logActivity(ctx.db, ctx.user, { action: 'doctor.update', description: `Updated ${d.name}`, subjectType: 'doctor', subjectId: d.id, subjectLabel: d.name }, ctx.now);
  return d;
});

route('DELETE', '/doctors/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.DOCTORS_DELETE);
  const d = ctx.db.doctors.find((x) => x.id === raw.params.id);
  if (!d) throw notFound();
  if (ctx.db.cases.some((c) => c.doctorId === d.id)) throw new ApiError(422, 'This doctor has cases. Set them to inactive instead.');
  ctx.db.doctors = ctx.db.doctors.filter((x) => x !== d);
  logActivity(ctx.db, ctx.user, { action: 'doctor.delete', description: `Deleted ${d.name}`, subjectType: 'doctor', subjectLabel: d.name }, ctx.now);
  return null;
});

/* -------------------------------- Clinics ------------------------------- */

function clinicItem(db: MockDatabase, k: Clinic, now: number): ClinicListItem {
  const cases = db.cases.filter((c) => c.clinicId === k.id);
  const outstanding = cases.reduce((s, c) => {
    const inv = c.invoiceId ? db.invoices.find((i) => i.id === c.invoiceId) : undefined;
    return s + (inv ? invoiceView(db, inv, now).remaining : 0);
  }, 0);
  return {
    ...k,
    doctorCount: db.doctors.filter((d) => d.clinicId === k.id).length,
    caseCount: cases.length,
    activeCases: cases.filter((c) => STATUS_META[c.status].open).length,
    outstanding: Math.round(outstanding * 100) / 100,
  };
}

route('GET', '/clinics', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, [PERMISSIONS.CLINICS_VIEW, PERMISSIONS.CASES_SUBMIT], 'any');
  const q = raw.query;
  const scope = clinicScope(ctx);
  const items = ctx.db.clinics
    .filter((k) => !scope || k.id === scope)
    .filter((k) => !qStr(q, 'status') || k.status === qStr(q, 'status'))
    .filter((k) => includesText([k.name, k.contactPerson, k.phone, k.email, k.address], qStr(q, 'search')))
    .map((k) => clinicItem(ctx.db, k, ctx.now));
  return paginate(sortItems(items, q, { name: (k) => k.name, caseCount: (k) => k.caseCount, activeCases: (k) => k.activeCases, outstanding: (k) => k.outstanding, status: (k) => k.status }, 'name'), q);
});

route('GET', '/clinics/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.CLINICS_VIEW);
  const k = ctx.db.clinics.find((x) => x.id === raw.params.id);
  if (!k) throw notFound();
  const cases = visibleCases(ctx).filter((c) => c.clinicId === k.id);
  const detail: ClinicDetail = { ...clinicItem(ctx.db, k, ctx.now), doctors: ctx.db.doctors.filter((d) => d.clinicId === k.id), stats: relationStats(ctx.db, cases, ctx.now), recentCases: recentCases(ctx.db, cases, ctx.now) };
  return detail;
});

function validateClinic(body: Partial<ClinicPayload>) {
  requireFields(body as Record<string, unknown>, { name: 'Clinic name', phone: 'Phone' });
  const errors: FieldErrors = {};
  checkEmail(errors, body.email);
  checkPhone(errors, body.phone, true);
  throwIf(errors);
}

function clinicFields(body: ClinicPayload) {
  return { name: body.name.trim(), contactPerson: body.contactPerson?.trim() ?? '', phone: body.phone.trim(), email: body.email?.trim() ?? '', address: body.address?.trim() ?? '', status: body.status ?? 'active', notes: body.notes?.trim() ?? '' };
}

route('POST', '/clinics', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.CLINICS_CREATE);
  const body = (raw.body ?? {}) as ClinicPayload;
  validateClinic(body);
  if (ctx.db.clinics.some((k) => k.name.toLowerCase() === body.name.trim().toLowerCase())) throw validationError({ name: ['A clinic with this name already exists.'] });
  const k: Clinic = { id: nextId('cln'), ...clinicFields(body), createdAt: toIso(ctx.now) };
  ctx.db.clinics.push(k);
  logActivity(ctx.db, ctx.user, { action: 'clinic.create', description: `Added clinic ${k.name}`, subjectType: 'clinic', subjectId: k.id, subjectLabel: k.name }, ctx.now);
  return k;
});

route('PUT', '/clinics/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.CLINICS_EDIT);
  const k = ctx.db.clinics.find((x) => x.id === raw.params.id);
  if (!k) throw notFound();
  const body = (raw.body ?? {}) as ClinicPayload;
  validateClinic(body);
  Object.assign(k, clinicFields(body));
  logActivity(ctx.db, ctx.user, { action: 'clinic.update', description: `Updated clinic ${k.name}`, subjectType: 'clinic', subjectId: k.id, subjectLabel: k.name }, ctx.now);
  return k;
});

route('DELETE', '/clinics/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.CLINICS_DELETE);
  const k = ctx.db.clinics.find((x) => x.id === raw.params.id);
  if (!k) throw notFound();
  if (ctx.db.cases.some((c) => c.clinicId === k.id) || ctx.db.doctors.some((d) => d.clinicId === k.id)) {
    throw new ApiError(422, 'This clinic has doctors or cases. Set it to inactive instead.');
  }
  ctx.db.clinics = ctx.db.clinics.filter((x) => x !== k);
  logActivity(ctx.db, ctx.user, { action: 'clinic.delete', description: `Deleted clinic ${k.name}`, subjectType: 'clinic', subjectLabel: k.name }, ctx.now);
  return null;
});

/* ------------------------------ Technicians ----------------------------- */

function techStats(db: MockDatabase, t: Technician, now: number): TechnicianListItem {
  return { ...t, ...technicianWorkload(db.cases.filter((c) => c.technicianId === t.id), now, slaConfig(db), localDay) };
}

route('GET', '/technicians', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, [PERMISSIONS.TECHNICIANS_VIEW, PERMISSIONS.CASES_ASSIGN], 'any');
  const q = raw.query;
  const active = qBool(q, 'active');
  const items = ctx.db.technicians
    .filter((t) => active === undefined || t.active === active)
    .filter((t) => includesText([t.name, t.email, t.phone, t.specialty], qStr(q, 'search')))
    .map((t) => techStats(ctx.db, t, ctx.now));
  return paginate(sortItems(items, q, { name: (t) => t.name, activeCases: (t) => t.activeCases, completedCases: (t) => t.completedCases, overdue: (t) => t.overdue, onTimeRate: (t) => t.onTimeRate }, 'name'), q);
});

route('GET', '/technicians/:id', (raw) => {
  const ctx = authenticate(raw);
  // Technicians may always open their own profile.
  if (ctx.user.technicianId !== raw.params.id) authorize(ctx, PERMISSIONS.TECHNICIANS_VIEW);
  const t = ctx.db.technicians.find((x) => x.id === raw.params.id);
  if (!t) throw notFound();
  const cases = ctx.db.cases.filter((c) => c.technicianId === t.id);
  const byDue = (a: LabCase, b: LabCase) => (a.dueAt ?? '').localeCompare(b.dueAt ?? '');
  const detail: TechnicianDetail = {
    ...techStats(ctx.db, t, ctx.now),
    qcPending: cases.filter((c) => c.status === 'quality_control').length,
    qcFailures: ctx.db.qualityChecks.filter((q) => q.result === 'failed' && cases.some((c) => c.id === q.caseId)).length,
    avgProductionHours: average(cases.map((c) => hoursBetween(c.productionStartedAt, c.productionCompletedAt)).filter((x): x is number => x !== null)),
    activeCaseList: cases.filter((c) => TECHNICIAN_ACTIVE_STATUSES.includes(c.status)).sort(byDue).map((c) => toListItem(ctx.db, c, ctx.now)),
    recentCompleted: cases
      .filter((c) => TECHNICIAN_FINISHED_STATUSES.includes(c.status))
      .sort((a, b) => (b.readyAt ?? '').localeCompare(a.readyAt ?? ''))
      .slice(0, 10)
      .map((c) => toListItem(ctx.db, c, ctx.now)),
  };
  return detail;
});

function validateTech(db: MockDatabase, body: Partial<TechnicianPayload>, selfId?: string) {
  requireFields(body as Record<string, unknown>, { name: 'Name', email: 'Email', phone: 'Phone', specialty: 'Specialty' });
  const errors: FieldErrors = {};
  checkEmail(errors, body.email, true);
  checkPhone(errors, body.phone, true);
  if (db.technicians.some((t) => t.id !== selfId && t.email.toLowerCase() === body.email!.trim().toLowerCase())) errors.email = ['Another technician uses this email.'];
  throwIf(errors);
}

route('POST', '/technicians', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.TECHNICIANS_CREATE);
  const body = (raw.body ?? {}) as TechnicianPayload;
  validateTech(ctx.db, body);
  const linked = ctx.db.users.find((u) => u.email.toLowerCase() === body.email.trim().toLowerCase() && u.role === 'technician');
  const t: Technician = { id: nextId('tec'), userId: linked?.id ?? null, name: body.name.trim(), email: body.email.trim(), phone: body.phone.trim(), specialty: body.specialty.trim(), active: body.active !== false, createdAt: toIso(ctx.now) };
  ctx.db.technicians.push(t);
  if (linked) linked.technicianId = t.id;
  logActivity(ctx.db, ctx.user, { action: 'technician.create', description: `Added technician ${t.name}`, subjectType: 'technician', subjectId: t.id, subjectLabel: t.name }, ctx.now);
  return t;
});

route('PUT', '/technicians/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.TECHNICIANS_EDIT);
  const t = ctx.db.technicians.find((x) => x.id === raw.params.id);
  if (!t) throw notFound();
  const body = (raw.body ?? {}) as TechnicianPayload;
  validateTech(ctx.db, body, t.id);
  if (body.active === false && ctx.db.cases.some((c) => c.technicianId === t.id && PRODUCTION_STATUSES.includes(c.status))) {
    throw new ApiError(422, `${t.name} still has cases in production. Reassign them before deactivating.`);
  }
  Object.assign(t, { name: body.name.trim(), email: body.email.trim(), phone: body.phone.trim(), specialty: body.specialty.trim(), active: body.active !== false });
  logActivity(ctx.db, ctx.user, { action: 'technician.update', description: `Updated technician ${t.name}`, subjectType: 'technician', subjectId: t.id, subjectLabel: t.name }, ctx.now);
  return t;
});

route('DELETE', '/technicians/:id', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.TECHNICIANS_DELETE);
  const t = ctx.db.technicians.find((x) => x.id === raw.params.id);
  if (!t) throw notFound();
  if (ctx.db.cases.some((c) => c.technicianId === t.id)) {
    throw new ApiError(422, `${t.name} has case history and cannot be deleted. Set the technician to inactive instead.`);
  }
  ctx.db.technicians = ctx.db.technicians.filter((x) => x !== t);
  ctx.db.users.forEach((u) => {
    if (u.technicianId === t.id) u.technicianId = null;
  });
  logActivity(ctx.db, ctx.user, { action: 'technician.delete', description: `Deleted technician ${t.name}`, subjectType: 'technician', subjectLabel: t.name }, ctx.now);
  return null;
});
