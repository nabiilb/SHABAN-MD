/**
 * Database seeding.
 *   seedBase — permission catalogue, default roles, lab settings and number
 *              sequences; idempotent and safe for production.
 *   seedDemo — wipes business data and loads the shared demo dataset (the same
 *              one the mock backend uses), with sample files written to storage.
 */
import { deflateSync, crc32 } from 'node:zlib';
import { buildDemoDataset } from '@48hrs/shared/demo-data';
import { samplePrescriptionPdf, sampleStlText } from '@48hrs/shared/demo-files';
import { DEFAULT_LAB_SETTINGS } from '@48hrs/shared/constants';
import { ALL_PERMISSION_KEYS, DEFAULT_ROLES, PERMISSION_CATALOGUE } from '@48hrs/shared/permissions';
import type { LabSettings } from '@48hrs/shared/types';
import type { Db } from '../lib/prisma.ts';
import { hashPassword } from '../lib/password.ts';
import { clearStorage, newStorageKey, writeObject } from '../lib/storage.ts';
import { SEQUENCES } from '../repositories/sequence-repository.ts';

const d = (v: string | null | undefined) => (v ? new Date(v) : null);

/** Tables holding business data, children first (TRUNCATE … CASCADE handles order anyway). */
const BUSINESS_TABLES = [
  'activity_log',
  'notifications',
  'payments',
  'invoices',
  'deliveries',
  'quality_issues',
  'quality_checks',
  'case_attachments',
  'case_assignments',
  'case_status_history',
  'case_notes',
  'cases',
  'lab_services',
  'patients',
  'password_reset_tokens',
  'sessions',
  'technicians',
  'users',
  'doctors',
  'clinics',
  'role_permissions',
  'roles',
  'permissions',
  'settings',
  'sequences',
];

export async function truncateAll(db: Db) {
  await db.$executeRawUnsafe(`TRUNCATE TABLE ${BUSINESS_TABLES.map((t) => `"${t}"`).join(', ')} RESTART IDENTITY CASCADE`);
}

/** Permission catalogue (always refreshed), default roles (created once), settings and sequences. */
export async function seedBase(db: Db, settings: LabSettings = DEFAULT_LAB_SETTINGS) {
  for (const p of PERMISSION_CATALOGUE) {
    await db.permission.upsert({ where: { key: p.key }, create: { key: p.key, label: p.label, group: p.group, description: p.description ?? null }, update: { label: p.label, group: p.group } });
  }
  for (const r of DEFAULT_ROLES) {
    const existing = await db.role.findUnique({ where: { key: r.key } });
    if (!existing) {
      await db.role.create({ data: { key: r.key, name: r.name, description: r.description, locked: r.locked } });
      await db.rolePermission.createMany({ data: r.permissions.map((permissionKey) => ({ roleKey: r.key, permissionKey })) });
    } else if (r.locked) {
      // A locked role always holds the full catalogue, including permissions added later.
      await db.rolePermission.createMany({ data: ALL_PERMISSION_KEYS.map((permissionKey) => ({ roleKey: r.key, permissionKey })), skipDuplicates: true });
    }
  }
  await db.setting.upsert({ where: { key: 'lab' }, create: { key: 'lab', value: { ...settings } }, update: {} });
  for (const name of Object.values(SEQUENCES)) {
    await db.sequence.upsert({ where: { name }, create: { name, value: 0 }, update: {} });
  }
}

/** First Super Admin for a fresh production database. No-op when the e-mail already exists. */
export async function seedAdmin(db: Db, admin: { email: string; name: string; password: string }) {
  const email = admin.email.trim().toLowerCase();
  if (await db.user.findUnique({ where: { email } })) return false;
  await db.user.create({ data: { name: admin.name, email, passwordHash: await hashPassword(admin.password), roleKey: 'super_admin' } });
  return true;
}

/* ------------------------------ Sample PNG ------------------------------ */

function pngChunk(type: string, data: Buffer) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body) >>> 0);
  return Buffer.concat([len, body, crc]);
}

/** A 480×320 navy placeholder "photo" with a lighter arch band — a real, viewable PNG. */
function samplePhotoPng() {
  const w = 480;
  const h = 320;
  const raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) {
    raw[y * (w * 3 + 1)] = 0;
    for (let x = 0; x < w; x++) {
      const dx = (x - w / 2) / 170;
      const dy = (y - h * 0.62) / 110;
      const r = dx * dx + dy * dy;
      const onArch = r > 0.82 && r < 1 && y < h * 0.62;
      const o = y * (w * 3 + 1) + 1 + x * 3;
      raw[o] = onArch ? 0x2f : 0x0a;
      raw[o + 1] = onArch ? 0x6f : 0x14;
      raw[o + 2] = onArch ? 0xc4 : 0x24;
    }
  }
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 2; // truecolour
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    pngChunk('IHDR', ihdr),
    pngChunk('IDAT', deflateSync(raw)),
    pngChunk('IEND', Buffer.alloc(0)),
  ]);
}

/* ------------------------------- Demo data ------------------------------ */

export interface DemoSeedOptions {
  /** Password given to every demo account (from SEED_USER_PASSWORD). */
  password: string;
  /** Pre-computed hash of `password`, so repeated resets (tests) skip re-hashing. */
  passwordHash?: string;
  now?: number;
  /** Write sample files for the seeded attachments (skipped by fast test resets). */
  writeFiles?: boolean;
}

export async function seedDemo(db: Db, { password, passwordHash, now = Date.now(), writeFiles = true }: DemoSeedOptions) {
  const data = buildDemoDataset(now);
  await truncateAll(db);
  await seedBase(db, data.settings);

  // Role permissions exactly as in the dataset (seedBase only creates missing roles).
  await db.rolePermission.deleteMany();
  await db.rolePermission.createMany({ data: data.roles.flatMap((r) => r.permissions.map((permissionKey) => ({ roleKey: r.key, permissionKey }))) });

  await db.clinic.createMany({
    data: data.clinics.map((k) => ({ id: k.id, name: k.name, contactPerson: k.contactPerson, phone: k.phone, email: k.email, address: k.address, status: k.status, notes: k.notes ?? '', createdAt: new Date(k.createdAt) })),
  });
  await db.doctor.createMany({
    data: data.doctors.map((x) => ({ id: x.id, name: x.name, clinicId: x.clinicId, phone: x.phone, email: x.email, specialty: x.specialty, status: x.status, createdAt: new Date(x.createdAt) })),
  });
  const hashes = passwordHash ? data.users.map(() => passwordHash) : await Promise.all(data.users.map(() => hashPassword(password)));
  await db.user.createMany({
    data: data.users.map((u, i) => ({
      id: u.id,
      name: u.name,
      email: u.email.toLowerCase(),
      phone: u.phone ?? '',
      passwordHash: hashes[i],
      roleKey: u.role,
      active: u.active,
      clinicId: u.clinicId ?? null,
      doctorId: u.doctorId ?? null,
      createdAt: new Date(u.createdAt),
    })),
  });
  await db.technician.createMany({
    data: data.technicians.map((t) => ({ id: t.id, userId: t.userId ?? null, name: t.name, email: t.email, phone: t.phone, specialty: t.specialty, active: t.active, createdAt: new Date(t.createdAt) })),
  });
  await db.patient.createMany({
    data: data.patients.map((p) => ({
      id: p.id,
      code: p.code,
      name: p.name,
      phone: p.phone ?? '',
      email: p.email ?? '',
      gender: p.gender ?? null,
      dateOfBirth: p.dateOfBirth ? new Date(`${p.dateOfBirth}T00:00:00Z`) : null,
      clinicId: p.clinicId ?? null,
      notes: p.notes ?? '',
      createdAt: new Date(p.createdAt),
    })),
  });
  await db.labService.createMany({
    data: data.services.map((s) => ({ id: s.id, name: s.name, caseType: s.caseType, unitMode: s.unitMode, unitPrice: s.unitPrice, defaultMaterial: s.defaultMaterial, active: s.active })),
  });

  const flags = data.deadlineFlags;
  await db.dentalCase.createMany({
    data: data.cases.map((c) => ({
      id: c.id,
      caseNumber: c.caseNumber,
      patientId: c.patientId,
      doctorId: c.doctorId,
      clinicId: c.clinicId,
      serviceId: c.serviceId,
      caseType: c.caseType,
      restorationType: c.restorationType,
      material: c.material,
      shade: c.shade,
      teeth: c.teeth,
      dentureType: c.dentureType ?? null,
      units: c.units,
      unitPrice: c.unitPrice,
      emergencyFee: c.emergencyFee,
      total: c.total,
      priority: c.priority,
      status: c.status,
      technicianId: c.technicianId ?? null,
      instructions: c.instructions,
      reworkCount: c.reworkCount,
      submittedAt: d(c.submittedAt),
      receivedAt: d(c.receivedAt),
      dueAt: d(c.dueAt),
      assignedAt: d(c.assignedAt),
      productionStartedAt: d(c.productionStartedAt),
      productionCompletedAt: d(c.productionCompletedAt),
      qcCompletedAt: d(c.qcCompletedAt),
      readyAt: d(c.readyAt),
      deliveredAt: d(c.deliveredAt),
      completedAt: d(c.completedAt),
      cancelledAt: d(c.cancelledAt),
      atRiskNotifiedAt: flags[c.id]?.atRisk ? new Date(now) : null,
      overdueNotifiedAt: flags[c.id]?.overdue ? new Date(now) : null,
      createdById: c.createdById,
      createdAt: new Date(c.createdAt),
      updatedAt: new Date(c.updatedAt),
    })),
  });
  await db.caseNote.createMany({
    data: data.cases.flatMap((c) => c.notes.map((n) => ({ id: n.id, caseId: c.id, text: n.text, authorId: n.authorId, createdAt: new Date(n.createdAt) }))),
  });
  await db.caseStatusHistory.createMany({
    data: data.history.map((h) => ({ id: h.id, caseId: h.caseId, fromStatus: h.fromStatus, toStatus: h.toStatus, userId: h.userId, userRole: h.userRole, note: h.note ?? null, createdAt: new Date(h.createdAt) })),
  });
  // Assignment log from the "→ assigned" history entries.
  await db.caseAssignment.createMany({
    data: data.cases
      .filter((c) => c.technicianId && c.assignedAt)
      .map((c) => {
        const h = data.history.find((x) => x.caseId === c.id && x.toStatus === 'assigned');
        return { caseId: c.id, technicianId: c.technicianId!, assignedById: h?.userId ?? c.createdById, note: h?.note ?? null, assignedAt: new Date(c.assignedAt!) };
      }),
  });

  if (writeFiles) await clearStorage();
  const png = writeFiles ? samplePhotoPng() : null;
  const attachments = [];
  for (const a of data.attachments) {
    const storageKey = newStorageKey(a.extension, new Date(a.createdAt));
    const c = data.cases.find((x) => x.id === a.caseId)!;
    let size = a.size;
    if (writeFiles) {
      const content = a.extension === 'pdf' ? samplePrescriptionPdf(c.caseNumber) : a.extension === 'stl' ? sampleStlText(a.name) : png!;
      await writeObject(storageKey, content);
      size = typeof content === 'string' ? Buffer.byteLength(content) : content.length;
    }
    attachments.push({ id: a.id, caseId: a.caseId, name: a.name, storageKey, mimeType: a.mimeType, extension: a.extension, size, category: a.category, uploadedById: a.uploadedById, createdAt: new Date(a.createdAt) });
  }
  await db.caseAttachment.createMany({ data: attachments });

  await db.qualityCheck.createMany({
    data: data.qualityChecks.map((q) => ({ id: q.id, caseId: q.caseId, result: q.result, reworkRequired: q.reworkRequired, notes: q.notes, checkedById: q.checkedById, checkedAt: new Date(q.checkedAt) })),
  });
  await db.qualityIssue.createMany({ data: data.qualityChecks.flatMap((q) => q.issues.map((issue) => ({ qualityCheckId: q.id, issue }))) });
  await db.delivery.createMany({
    data: data.deliveries.map((x) => ({
      id: x.id,
      caseId: x.caseId,
      status: x.status,
      method: x.method,
      courierName: x.courierName ?? null,
      deliveredTo: x.deliveredTo ?? null,
      receivedBy: x.receivedBy ?? null,
      notes: x.notes ?? null,
      dispatchedAt: d(x.dispatchedAt),
      deliveredAt: d(x.deliveredAt),
      recordedById: x.recordedById,
      createdAt: new Date(x.createdAt),
    })),
  });

  const paidByInvoice = new Map<string, number>();
  data.payments.forEach((p) => paidByInvoice.set(p.invoiceId, (paidByInvoice.get(p.invoiceId) ?? 0) + p.amount));
  await db.invoice.createMany({
    data: data.invoices.map((i) => ({
      id: i.id,
      invoiceNumber: i.invoiceNumber,
      caseId: i.caseId,
      patientId: i.patientId,
      doctorId: i.doctorId,
      clinicId: i.clinicId,
      subtotal: i.subtotal,
      emergencyFee: i.emergencyFee,
      discount: i.discount,
      total: i.total,
      amountPaid: Math.round((paidByInvoice.get(i.id) ?? 0) * 100) / 100,
      issuedAt: new Date(i.issuedAt),
      dueDate: new Date(i.dueDate),
    })),
  });
  await db.payment.createMany({
    data: data.payments.map((p) => ({ id: p.id, invoiceId: p.invoiceId, amount: p.amount, method: p.method, reference: p.reference ?? '', notes: p.notes ?? '', receivedById: p.receivedById, paidAt: new Date(p.paidAt) })),
  });
  await db.notification.createMany({
    data: data.notifications.map((n) => ({ id: n.id, userId: n.userId, type: n.type, title: n.title, message: n.message, caseId: n.caseId ?? null, readAt: d(n.readAt), createdAt: new Date(n.createdAt) })),
  });

  await db.sequence.update({ where: { name: SEQUENCES.case }, data: { value: data.counters.case } });
  await db.sequence.update({ where: { name: SEQUENCES.invoice }, data: { value: data.counters.invoice } });
  await db.sequence.update({ where: { name: SEQUENCES.patient }, data: { value: data.counters.patient } });

  return { users: data.users.length, cases: data.cases.length, invoices: data.invoices.length, attachments: attachments.length };
}
