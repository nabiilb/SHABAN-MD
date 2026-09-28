/**
 * Demo dataset for the mock API. Timestamps are generated relative to "now" so
 * the 48-hour board always shows a realistic mix of on-track, at-risk and
 * overdue work. Names and services follow the prototype.
 */
import type {
  CaseAttachment,
  CaseStatus,
  CaseStatusHistory,
  Clinic,
  Delivery,
  DeliveryMethod,
  Doctor,
  LabCase,
  LabService,
  LabSettings,
  Patient,
  Payment,
  PaymentMethod,
  QcIssue,
  QualityCheck,
  RoleKey,
  Technician,
  AppNotification,
} from '@/types/models';
import { DEFAULT_ROLES } from '@/lib/permissions';
import { HOUR_MS } from '@/lib/sla';
import { round2 } from '@/lib/billing';
import { caseNumber, invoiceNumber } from '@/utils/case-keys';
import { toIso } from '@/utils/dates';
import type { MockDatabase, MockUser } from './db';
import { DB_VERSION } from './db';

const DAY_MS = 24 * HOUR_MS;

/** Deterministic PRNG so every reset produces the same shape of data. */
function mulberry32(seed: number) {
  let a = seed;
  return () => {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

export const SEED_SETTINGS: LabSettings = {
  labName: '48HRS Dental Lab',
  phone: '+252 61 000 4848',
  email: 'lab@48hrs.lab',
  address: 'Maka Al-Mukarama Road, Mogadishu',
  currency: 'USD',
  slaHours: 48,
  atRiskHours: 12,
  criticalHours: 4,
  emergencyFeePerUnit: 5,
  invoiceDueDays: 14,
};

const SERVICES: LabService[] = [
  { id: 'svc_zirconia', name: 'Zirconia Crown', caseType: 'crown', unitMode: 'tooth', unitPrice: 20, defaultMaterial: 'Zirconia', active: true },
  { id: 'svc_pfm', name: 'PFM Crown', caseType: 'crown', unitMode: 'tooth', unitPrice: 15, defaultMaterial: 'Porcelain fused to metal', active: true },
  { id: 'svc_emax', name: 'E-Max Crown', caseType: 'crown', unitMode: 'tooth', unitPrice: 25, defaultMaterial: 'Lithium disilicate (E-Max)', active: true },
  { id: 'svc_veneer', name: 'Veneer', caseType: 'veneer', unitMode: 'tooth', unitPrice: 20, defaultMaterial: 'Lithium disilicate (E-Max)', active: true },
  { id: 'svc_bridge', name: 'Bridge', caseType: 'bridge', unitMode: 'tooth', unitPrice: 20, defaultMaterial: 'Zirconia', active: true },
  { id: 'svc_implant', name: 'Implant Crown', caseType: 'implant', unitMode: 'tooth', unitPrice: 35, defaultMaterial: 'Titanium abutment + zirconia', active: true },
  { id: 'svc_denture', name: 'Denture', caseType: 'denture', unitMode: 'denture', unitPrice: 120, defaultMaterial: 'Acrylic', active: true },
  { id: 'svc_guard', name: 'Night Guard', caseType: 'appliance', unitMode: 'arch', unitPrice: 18, defaultMaterial: 'Hard-soft EVA', active: true },
];

/** Salted SHA-256 hashes of the demo password — no plain-text passwords are stored. */
const HASHES: Record<string, [string, string]> = {
  'khalid@48hrs.lab': ['3e4ecea61dda1ce5', '3db03ff24fc2e21f1f51d3662fe444fbca7c26cfd10849742d61004f81dcc6f0'],
  'hodan@48hrs.lab': ['198fe95ad5eb06a5', 'fb0bcb4da1fb44216a260f477d916a2518039fa27fd6e34533e7a52ac6894c96'],
  'omar@48hrs.lab': ['504d26f747d8e0e0', '4b9b2d2a30a1d5e0498cb28999acd62111bd6c4bb3dc3801a5ef72f06e6cf7ac'],
  'sagal@48hrs.lab': ['931beb4026addb92', 'a9038482c2e173c78bb7f297f9ec99628539daee7df157c8ab4da305f7ac3adf'],
  'fatima@48hrs.lab': ['ad1259e5b1f2fc5d', 'e2a8b9222d0f550da1bb5bc1b19c4a218003672b6c343a09e1c2a11d0d22073a'],
  'ahmed@48hrs.lab': ['bd79d968a8f327e4', '4021a173a20924628b77050a308229f79334ccc36803317179ad2fc225d95760'],
  'ali@48hrs.lab': ['e44734d080334b7f', '1f2c533828b920e46f9fc5456717bbcee0fcbe8d30fb1d1c8cd7b06ee07b1153'],
  'maryan@48hrs.lab': ['a63e9881f5213148', '0c1ebbcbbb7cb39fb55b41db1d26cf544eb63e5868b3a008bd3a8ec1cd148e1f'],
  'idil@48hrs.lab': ['4759215081e36819', 'ab55e37cb9db432e3f05f5e720cf6f61d17d4a27703eca19ccb2f8ab0eedcbe1'],
  'bashir@48hrs.lab': ['5da3dcef3da3d8c0', 'd86451e410e23f496f332f8f4bb24f4fa14652b84772462bc16816908fc952de'],
  'amina@smiledental.so': ['53a2b0dbfb2371bd', '2eba9220d7cede2d691afb9c7cb8502400f6c6e010085dde8b5564cad1afcc3d'],
  'layla@horizondental.so': ['6170bfc27112a5ee', 'b754565fe4195978aa223215d4de09cf3f08b0ae89b5a9f537d983d531c0cc96'],
};

const PATIENT_NAMES = [
  'Ahmed Mohamed', 'Sahra Abdi', 'Yusuf Ali', 'Hodan Jama', 'Mohamed Osman', 'Asha Warsame', 'Ibrahim Nur', 'Deqa Hassan',
  'Abdi Farah', 'Amina Ismail', 'Khadra Aden', 'Said Barre', 'Faisal Yusuf', 'Nimco Ahmed', 'Hassan Gedi', 'Ubah Mohamud',
  'Abdullahi Omar', 'Fartun Ali', 'Mahad Hirsi', 'Ifrah Abdullahi', 'Bile Jama', 'Sagal Hussein', 'Guled Farah', 'Hamdi Nur',
  'Liban Adan', 'Muna Salad', 'Omar Dirie', 'Safiya Abdi', 'Zakaria Musa', 'Ayaan Warsame', 'Jibril Hassan', 'Naima Osman',
  'Kaltun Ahmed', 'Mustafa Egal', 'Ilhan Yasin', 'Hibaaq Mohamed',
];

const INSTRUCTIONS = [
  'Full contour, light glaze. Match adjacent dentition.',
  'Anterior, high translucency. Incisal effects requested.',
  'Posterior units, heavy occlusion — reduce cusp height if needed.',
  'Tight contacts please. Margin 0.5 mm chamfer.',
  'Minimal prep veneers. Keep natural texture.',
  'Screw-retained implant crown, access hole lingual.',
  'Hard-soft night guard, full arch.',
  'Complete denture, medium teeth mould, pink gingiva.',
];

type Spec = {
  status: CaseStatus;
  /** Hours since the lab received the case (ignored for pre-intake). */
  elapsed?: number;
  /** For finished cases: hours from receipt to delivery. */
  turnaround?: number;
  /** Days ago the case was received (historical cases). */
  daysAgo?: number;
  qcFail?: boolean;
  priority?: LabCase['priority'];
  paid?: 'full' | 'partial' | 'none';
};

export function buildSeed(now: number): MockDatabase {
  const rnd = mulberry32(4848);
  const pick = <T,>(arr: readonly T[]) => arr[Math.floor(rnd() * arr.length)];
  const createdBase = toIso(now - 400 * DAY_MS);
  let idCounter = 1000;
  const id = (p: string) => `${p}_${(idCounter++).toString(36)}`;

  /* ----------------------------- Directory ------------------------------ */

  const clinics: Clinic[] = [
    { id: 'cln_smile', name: 'Smile Dental Clinic', contactPerson: 'Dr. Amina Yusuf', phone: '+252 61 555 0101', email: 'info@smiledental.so', address: 'KM4, Hodan District, Mogadishu', status: 'active', notes: 'Prefers lab courier after 14:00.', createdAt: createdBase },
    { id: 'cln_horizon', name: 'Horizon Dental Care', contactPerson: 'Dr. Layla Omar', phone: '+252 61 555 0202', email: 'contact@horizondental.so', address: 'Maka Al-Mukarama Rd, Waberi', status: 'active', notes: '', createdAt: createdBase },
    { id: 'cln_banadir', name: 'Banadir Dental Centre', contactPerson: 'Dr. Abdirahman Ali', phone: '+252 61 555 0303', email: 'desk@banadirdental.so', address: 'Bakara Area, Mogadishu', status: 'active', notes: 'Monthly statement billing.', createdAt: createdBase },
    { id: 'cln_aurora', name: 'Aurora Dental Studio', contactPerson: 'Dr. Hibo Mohamed', phone: '+252 61 555 0404', email: 'hello@auroradental.so', address: 'Soma Mall, Wadajir', status: 'active', notes: '', createdAt: createdBase },
    { id: 'cln_hodan', name: 'Hodan Family Dental', contactPerson: 'Dr. Nasra Ahmed', phone: '+252 61 555 0505', email: 'frontdesk@hodanfamily.so', address: 'Taleex, Hodan District', status: 'inactive', notes: 'Account on hold since August.', createdAt: createdBase },
  ];

  const doctors: Doctor[] = [
    { id: 'doc_amina', name: 'Dr. Amina Yusuf', clinicId: 'cln_smile', phone: '+252 61 700 1001', email: 'amina@smiledental.so', specialty: 'Prosthodontics', status: 'active', createdAt: createdBase },
    { id: 'doc_yasin', name: 'Dr. Yasin Warsame', clinicId: 'cln_smile', phone: '+252 61 700 1002', email: 'yasin@smiledental.so', specialty: 'General dentistry', status: 'active', createdAt: createdBase },
    { id: 'doc_layla', name: 'Dr. Layla Omar', clinicId: 'cln_horizon', phone: '+252 61 700 1003', email: 'layla@horizondental.so', specialty: 'Cosmetic dentistry', status: 'active', createdAt: createdBase },
    { id: 'doc_abdirahman', name: 'Dr. Abdirahman Ali', clinicId: 'cln_banadir', phone: '+252 61 700 1004', email: 'abdirahman@banadirdental.so', specialty: 'Implantology', status: 'active', createdAt: createdBase },
    { id: 'doc_hibo', name: 'Dr. Hibo Mohamed', clinicId: 'cln_aurora', phone: '+252 61 700 1005', email: 'hibo@auroradental.so', specialty: 'Restorative dentistry', status: 'active', createdAt: createdBase },
    { id: 'doc_nasra', name: 'Dr. Nasra Ahmed', clinicId: 'cln_hodan', phone: '+252 61 700 1006', email: 'nasra@hodanfamily.so', specialty: 'General dentistry', status: 'inactive', createdAt: createdBase },
  ];
  const activeDoctors = doctors.filter((d) => d.status === 'active');

  const technicians: Technician[] = [
    { id: 'tec_fatima', userId: 'usr_fatima', name: 'Fatima Nur', email: 'fatima@48hrs.lab', phone: '+252 61 800 2001', specialty: 'Crown & bridge', active: true, createdAt: createdBase },
    { id: 'tec_ahmed', userId: 'usr_ahmed', name: 'Ahmed Hassan', email: 'ahmed@48hrs.lab', phone: '+252 61 800 2002', specialty: 'Ceramics & veneers', active: true, createdAt: createdBase },
    { id: 'tec_ali', userId: 'usr_ali', name: 'Ali Mohamud', email: 'ali@48hrs.lab', phone: '+252 61 800 2003', specialty: 'CAD/CAM milling', active: true, createdAt: createdBase },
    { id: 'tec_maryan', userId: 'usr_maryan', name: 'Maryan Abdi', email: 'maryan@48hrs.lab', phone: '+252 61 800 2004', specialty: 'Removables & dentures', active: true, createdAt: createdBase },
  ];

  const mkUser = (uid: string, name: string, email: string, role: RoleKey, extra: Partial<MockUser> = {}): MockUser => ({
    id: uid,
    name,
    email,
    role,
    phone: '',
    active: true,
    clinicId: null,
    doctorId: null,
    technicianId: null,
    lastLoginAt: null,
    createdAt: createdBase,
    passwordSalt: HASHES[email][0],
    passwordHash: HASHES[email][1],
    ...extra,
  });

  const users: MockUser[] = [
    mkUser('usr_khalid', 'Khalid Aden', 'khalid@48hrs.lab', 'super_admin'),
    mkUser('usr_hodan', 'Hodan Abdi', 'hodan@48hrs.lab', 'admin'),
    mkUser('usr_omar', 'Omar Farah', 'omar@48hrs.lab', 'lab_manager'),
    mkUser('usr_sagal', 'Sagal Warsame', 'sagal@48hrs.lab', 'reception'),
    mkUser('usr_fatima', 'Fatima Nur', 'fatima@48hrs.lab', 'technician', { technicianId: 'tec_fatima' }),
    mkUser('usr_ahmed', 'Ahmed Hassan', 'ahmed@48hrs.lab', 'technician', { technicianId: 'tec_ahmed' }),
    mkUser('usr_ali', 'Ali Mohamud', 'ali@48hrs.lab', 'technician', { technicianId: 'tec_ali' }),
    mkUser('usr_maryan', 'Maryan Abdi', 'maryan@48hrs.lab', 'technician', { technicianId: 'tec_maryan' }),
    mkUser('usr_idil', 'Idil Hassan', 'idil@48hrs.lab', 'qc'),
    mkUser('usr_bashir', 'Bashir Omar', 'bashir@48hrs.lab', 'delivery'),
    mkUser('usr_amina', 'Dr. Amina Yusuf', 'amina@smiledental.so', 'client', { clinicId: 'cln_smile', doctorId: 'doc_amina' }),
    mkUser('usr_layla', 'Dr. Layla Omar', 'layla@horizondental.so', 'client', { clinicId: 'cln_horizon', doctorId: 'doc_layla', active: false }),
  ];
  const userById = (uid: string) => users.find((u) => u.id === uid)!;

  const patients: Patient[] = PATIENT_NAMES.map((name, i) => {
    const clinic = clinics[i % 4];
    return {
      id: `pat_${1024 + i}`,
      code: `PT-${1024 + i}`,
      name,
      phone: `+252 61 9${String(100000 + i * 3791).slice(0, 6)}`,
      email: i % 3 === 0 ? `${name.split(' ')[0].toLowerCase()}.${1024 + i}@mail.so` : '',
      gender: i % 2 === 0 ? 'male' : 'female',
      dateOfBirth: new Date(Date.UTC(1960 + (i * 7) % 45, (i * 5) % 12, 1 + (i * 3) % 27)).toISOString().slice(0, 10),
      clinicId: clinic.id,
      notes: i % 9 === 0 ? 'Sensitive to cold; handle impressions quickly.' : '',
      createdAt: toIso(now - (300 - i * 5) * DAY_MS),
    };
  });

  /* ------------------------------- Cases -------------------------------- */

  const cases: LabCase[] = [];
  const history: CaseStatusHistory[] = [];
  const qualityChecks: QualityCheck[] = [];
  const deliveries: Delivery[] = [];
  const invoices: MockDatabase['invoices'] = [];
  const payments: Payment[] = [];
  const attachments: CaseAttachment[] = [];
  let caseSeq = 70;
  let invSeq = 70;

  const actor = {
    reception: userById('usr_sagal'),
    manager: userById('usr_omar'),
    qc: userById('usr_idil'),
    delivery: userById('usr_bashir'),
  };

  const specs: Spec[] = [];
  // Historical work: roughly 2 cases a day over the last three months.
  for (let d = 90; d >= 2; d -= 1 + Math.floor(rnd() * 2)) {
    const late = rnd() < 0.1;
    specs.push({
      status: rnd() < 0.55 ? 'completed' : 'delivered',
      daysAgo: d + rnd() * 0.6,
      turnaround: late ? 49 + rnd() * 12 : 18 + rnd() * 28,
      qcFail: rnd() < 0.12,
      priority: rnd() < 0.1 ? 'urgent' : rnd() < 0.2 ? 'high' : 'normal',
      paid: d > 20 ? (rnd() < 0.85 ? 'full' : rnd() < 0.5 ? 'partial' : 'none') : rnd() < 0.5 ? 'full' : rnd() < 0.5 ? 'partial' : 'none',
    });
  }
  specs.push({ status: 'cancelled', daysAgo: 12 });
  specs.push({ status: 'rejected', daysAgo: 6 });
  // Live 48-hour board.
  const live: Spec[] = [
    { status: 'received', elapsed: 0.4, paid: 'none' },
    { status: 'received', elapsed: 1.3, paid: 'full', priority: 'urgent' },
    { status: 'review', elapsed: 2.2, paid: 'none' },
    { status: 'assigned', elapsed: 3.5, paid: 'full' },
    { status: 'assigned', elapsed: 7, paid: 'partial', priority: 'high' },
    { status: 'in_production', elapsed: 9, paid: 'full' },
    { status: 'in_production', elapsed: 18, paid: 'none' },
    { status: 'in_production', elapsed: 27, paid: 'full' },
    { status: 'in_production', elapsed: 37.5, paid: 'partial' },
    { status: 'in_production', elapsed: 45.2, paid: 'full', priority: 'urgent' },
    { status: 'in_production', elapsed: 51, paid: 'none' },
    { status: 'rework', elapsed: 40, paid: 'full', qcFail: true },
    { status: 'quality_control', elapsed: 25, paid: 'full' },
    { status: 'quality_control', elapsed: 44.5, paid: 'none' },
    { status: 'ready', elapsed: 31, paid: 'full' },
    { status: 'ready', elapsed: 49.5, paid: 'partial' },
    { status: 'out_for_delivery', elapsed: 39, paid: 'full' },
    { status: 'delivered', elapsed: 30, turnaround: 26, paid: 'full' },
    { status: 'submitted', elapsed: 0.6 },
    { status: 'submitted', elapsed: 2.4, priority: 'high' },
    { status: 'correction', elapsed: 5 },
  ];
  specs.push(...live);

  const techForService = (svc: LabService): Technician => {
    if (svc.unitMode === 'denture') return technicians[3];
    if (svc.id === 'svc_emax' || svc.id === 'svc_veneer') return rnd() < 0.7 ? technicians[1] : technicians[0];
    return pick([technicians[0], technicians[1], technicians[2], technicians[0], technicians[2]]);
  };

  const teethFor = (svc: LabService): number[] => {
    if (svc.unitMode !== 'tooth') return [];
    if (svc.id === 'svc_veneer') return pick([[7, 8, 9, 10], [6, 7, 8, 9, 10, 11], [8, 9], [7, 10]]);
    if (svc.id === 'svc_bridge') return pick([[3, 4, 5], [12, 13, 14], [19, 20, 21], [28, 29, 30]]);
    if (svc.id === 'svc_emax') return pick([[8], [9], [8, 9], [7]]);
    const quad = pick([[2, 3, 4, 5], [12, 13, 14, 15], [18, 19, 20], [28, 29, 30, 31], [14], [19], [30], [3]]);
    return quad.slice(0, 1 + Math.floor(rnd() * quad.length));
  };

  const addHistory = (c: LabCase, from: CaseStatus | null, to: CaseStatus, at: number, uid: string, note?: string) => {
    const u = userById(uid);
    history.push({ id: id('hst'), caseId: c.id, fromStatus: from, toStatus: to, userId: u.id, userName: u.name, userRole: u.role, note, createdAt: toIso(at) });
  };

  specs.forEach((spec) => {
    const svc = pick(SERVICES);
    const doctor = pick(activeDoctors);
    const clinicPatients = patients.filter((p) => p.clinicId === doctor.clinicId);
    const patient = pick(clinicPatients.length ? clinicPatients : patients);
    const teeth = teethFor(svc);
    const dentureType = svc.unitMode === 'denture' ? pick(['full_upper', 'full_lower', 'upper_lower', 'partial'] as const) : null;
    const units = svc.unitMode === 'tooth' ? teeth.length : dentureType === 'upper_lower' ? 2 : 1;
    const priority = spec.priority ?? 'normal';
    const emergencyFee = priority === 'urgent' ? round2(SEED_SETTINGS.emergencyFeePerUnit * units) : 0;
    const total = round2(svc.unitPrice * units + emergencyFee);
    const portal = doctor.clinicId === 'cln_smile' || doctor.clinicId === 'cln_horizon';
    const clientUser = users.find((u) => u.role === 'client' && u.clinicId === doctor.clinicId);
    const tech = techForService(svc);

    const R = spec.elapsed !== undefined ? now - spec.elapsed * HOUR_MS : now - (spec.daysAgo ?? 1) * DAY_MS;
    const preIntake = spec.status === 'submitted' || spec.status === 'correction' || spec.status === 'rejected';
    caseSeq += 1;
    const c: LabCase = {
      id: `cas_${caseSeq}`,
      caseNumber: caseNumber(new Date(R).getFullYear(), caseSeq),
      patientId: patient.id,
      doctorId: doctor.id,
      clinicId: doctor.clinicId,
      serviceId: svc.id,
      caseType: svc.caseType,
      restorationType: svc.name,
      material: svc.defaultMaterial,
      shade: svc.unitMode === 'arch' ? 'Clear' : pick(['A1', 'A2', 'A2', 'A3', 'B1', 'B2', 'C1']),
      teeth,
      dentureType,
      units,
      unitPrice: svc.unitPrice,
      emergencyFee,
      total,
      priority,
      status: spec.status,
      technicianId: null,
      instructions: pick(INSTRUCTIONS),
      notes: [],
      reworkCount: 0,
      submittedAt: portal || preIntake ? toIso(R - 0.3 * HOUR_MS) : null,
      receivedAt: null,
      dueAt: null,
      paymentStatus: 'unpaid',
      createdById: portal && clientUser ? clientUser.id : actor.reception.id,
      createdAt: toIso(portal || preIntake ? R - 0.3 * HOUR_MS : R),
      updatedAt: toIso(R),
    };
    const creator = portal && clientUser ? clientUser.id : actor.reception.id;

    if (preIntake) {
      addHistory(c, null, 'submitted', R - 0.3 * HOUR_MS, clientUser?.id ?? actor.reception.id, 'Submitted through the clinic portal');
      if (spec.status === 'correction') addHistory(c, 'submitted', 'correction', R + 0.5 * HOUR_MS, actor.reception.id, 'Impression file unreadable. Please re-upload the STL.');
      if (spec.status === 'rejected') addHistory(c, 'submitted', 'rejected', R + 1 * HOUR_MS, actor.reception.id, 'Duplicate of an earlier submission.');
      c.updatedAt = toIso(R + HOUR_MS);
      cases.push(c);
      seedAttachments(c, creator, R);
      return;
    }

    // Lab receipt starts the 48-hour clock.
    c.receivedAt = toIso(R);
    c.dueAt = toIso(R + SEED_SETTINGS.slaHours * HOUR_MS);
    if (c.submittedAt) addHistory(c, null, 'submitted', R - 0.3 * HOUR_MS, creator, 'Submitted through the clinic portal');
    addHistory(c, c.submittedAt ? 'submitted' : null, 'received', R, actor.reception.id, c.submittedAt ? 'Accepted by Reception' : 'Registered at reception');

    const finished = spec.status === 'delivered' || spec.status === 'completed';
    const T = finished ? (spec.turnaround ?? 30) : (spec.elapsed ?? 24);
    const at = (f: number) => R + f * T * HOUR_MS;
    const order: CaseStatus[] = ['received', 'review', 'assigned', 'in_production', 'quality_control', 'ready', 'out_for_delivery', 'delivered', 'completed'];
    const targetIdx = spec.status === 'rework' ? order.indexOf('quality_control') : spec.status === 'cancelled' ? 3 : order.indexOf(spec.status);
    const reached = (s: CaseStatus) => order.indexOf(s) <= targetIdx;
    let last: CaseStatus = 'received';
    const step = (to: CaseStatus, t: number, uid: string, note?: string) => {
      addHistory(c, last, to, t, uid, note);
      last = to;
      c.updatedAt = toIso(t);
    };

    const invoice = {
      id: `inv_${invSeq + 1}`,
      invoiceNumber: invoiceNumber(new Date(R).getFullYear(), ++invSeq),
      caseId: c.id,
      patientId: c.patientId,
      doctorId: c.doctorId,
      clinicId: c.clinicId,
      subtotal: round2(total - emergencyFee),
      emergencyFee,
      discount: 0,
      total,
      issuedAt: toIso(R),
      dueDate: toIso(R + SEED_SETTINGS.invoiceDueDays * DAY_MS),
    };

    if (spec.status !== 'cancelled') {
      invoices.push(invoice);
      c.invoiceId = invoice.id;
    }

    if (rnd() < 0.5 && reached('review')) step('review', at(0.02), actor.manager.id);
    if (reached('assigned')) {
      c.technicianId = tech.id;
      c.assignedAt = toIso(at(0.04));
      step('assigned', at(0.04), actor.manager.id, `Assigned to ${tech.name}`);
    }
    if (reached('in_production')) {
      c.productionStartedAt = toIso(at(0.07));
      step('in_production', at(0.07), tech.userId!);
      c.notes.push({ id: id('note'), text: 'Model poured and scanned. Design approved.', authorId: tech.userId!, authorName: tech.name, createdAt: toIso(at(0.15)) });
    }
    if (spec.status === 'cancelled') {
      c.status = 'cancelled';
      c.cancelledAt = toIso(at(0.2));
      step('cancelled', at(0.2), actor.manager.id, 'Clinic cancelled — patient postponed treatment.');
      cases.push(c);
      return;
    }
    if (reached('quality_control')) {
      if (spec.qcFail) {
        step('quality_control', at(0.55), tech.userId!);
        const failAt = at(0.6);
        const issue: QcIssue = pick(['shade', 'contacts', 'margins', 'occlusion']);
        qualityChecks.push({ id: id('qc'), caseId: c.id, result: 'failed', reworkRequired: true, issues: [issue], notes: issue === 'shade' ? 'Shade mismatch. Please correct to ' + c.shade + '.' : 'Open contact distal — rebuild and re-glaze.', checkedById: actor.qc.id, checkedByName: actor.qc.name, checkedAt: toIso(failAt) });
        step('rework', failAt, actor.qc.id, 'QC failed — returned for rework');
        c.reworkCount = 1;
        if (spec.status !== 'rework') {
          step('in_production', at(0.63), tech.userId!, 'Rework started');
        }
      }
      if (spec.status !== 'rework') {
        c.productionCompletedAt = toIso(at(0.75));
        step('quality_control', at(0.75), tech.userId!, 'Production completed');
      }
    }
    if (reached('ready')) {
      c.qcCompletedAt = toIso(at(0.85));
      c.readyAt = c.qcCompletedAt;
      qualityChecks.push({ id: id('qc'), caseId: c.id, result: 'passed', reworkRequired: false, issues: [], notes: 'Fit, margins and occlusion verified on model.', checkedById: actor.qc.id, checkedByName: actor.qc.name, checkedAt: c.qcCompletedAt });
      step('ready', at(0.85), actor.qc.id, 'QC passed');
      const method: DeliveryMethod = portal ? 'lab_courier' : pick(['clinic_pickup', 'lab_courier', 'third_party'] as const);
      const dlv: Delivery = { id: id('dlv'), caseId: c.id, status: 'ready', method, recordedById: actor.delivery.id, recordedByName: actor.delivery.name, createdAt: c.readyAt, dispatchedAt: null, deliveredAt: null };
      if (reached('out_for_delivery') && method !== 'clinic_pickup') {
        dlv.status = 'out_for_delivery';
        dlv.dispatchedAt = toIso(at(0.93));
        dlv.courierName = method === 'lab_courier' ? 'Bashir Omar' : 'Dhl Express Mogadishu';
        step('out_for_delivery', at(0.93), actor.delivery.id, `Dispatched via ${method === 'lab_courier' ? 'lab courier' : 'third-party delivery'}`);
      }
      if (reached('delivered')) {
        c.deliveredAt = toIso(at(1));
        dlv.status = 'delivered';
        dlv.deliveredAt = c.deliveredAt;
        dlv.deliveredTo = c.clinicId === 'cln_smile' ? 'Smile Dental Clinic reception' : 'Clinic front desk';
        dlv.receivedBy = pick(['Nurse Hawa', 'Front desk — Farhiya', 'Dr. assistant Abdi', 'Receptionist Ladan']);
        step('delivered', at(1), actor.delivery.id);
      }
      deliveries.push(dlv);
    }
    if (spec.status === 'completed') {
      c.completedAt = toIso(at(1) + 2 * HOUR_MS);
      step('completed', at(1) + 2 * HOUR_MS, clientUser?.id ?? actor.reception.id, 'Receipt confirmed');
    }
    c.status = spec.status;

    // Payments
    const pay = spec.paid ?? 'none';
    if (pay !== 'none' && c.invoiceId) {
      const method: PaymentMethod = pick(['cash', 'bank_transfer', 'mobile_money', 'mobile_money']);
      const amount = pay === 'full' ? total : round2(total * 0.5);
      const paidAt = finished && c.deliveredAt ? new Date(c.deliveredAt).getTime() + rnd() * 3 * DAY_MS : R + 0.2 * HOUR_MS;
      payments.push({ id: id('pay'), invoiceId: c.invoiceId, amount, method, reference: method === 'cash' ? '' : `TX${Math.floor(rnd() * 9e8 + 1e8)}`, notes: '', receivedById: actor.reception.id, receivedByName: actor.reception.name, paidAt: toIso(Math.min(paidAt, now - 60_000)) });
    }

    cases.push(c);
    seedAttachments(c, creator, R);
  });

  function seedAttachments(c: LabCase, uploaderId: string, at: number) {
    const u = userById(uploaderId);
    const short = c.caseNumber.slice(-3);
    const files: [string, string, number, CaseAttachment['category']][] = [
      [`prescription-${short}.pdf`, 'application/pdf', 1_153_434, 'prescription'],
      [`intraoral-photo-${short}.png`, 'image/png', 842_112, 'photo'],
    ];
    if (c.teeth.length) files.push([`scan-${c.teeth[0] <= 16 ? 'upper' : 'lower'}-${short}.stl`, 'model/stl', 19_293_798, 'scan']);
    files.forEach(([name, mime, size, category]) =>
      attachments.push({ id: id('att'), caseId: c.id, name, mimeType: mime, extension: name.split('.').pop()!, size, category, uploadedById: u.id, uploadedByName: u.name, createdAt: new Date(at - 0.3 * HOUR_MS).toISOString(), url: null }),
    );
  }

  /* --------------------------- Notifications ---------------------------- */

  const notifications: AppNotification[] = [];
  const note = (uid: string, type: AppNotification['type'], title: string, message: string, c: LabCase | undefined, hoursAgo: number, read = false) =>
    notifications.push({ id: id('ntf'), userId: uid, type, title, message, caseId: c?.id ?? null, caseNumber: c?.caseNumber ?? null, createdAt: toIso(now - hoursAgo * HOUR_MS), readAt: read ? toIso(now - (hoursAgo - 0.1) * HOUR_MS) : null });

  const byStatus = (s: CaseStatus) => cases.filter((c) => c.status === s);
  byStatus('submitted').forEach((c, i) => ['usr_sagal', 'usr_hodan'].forEach((u) => note(u, 'case_submitted', 'New case submitted', `${c.caseNumber} was submitted by ${clinics.find((k) => k.id === c.clinicId)?.name}.`, c, 0.5 + i)));
  byStatus('quality_control').forEach((c, i) => ['usr_idil', 'usr_omar'].forEach((u) => note(u, 'qc_required', 'Quality control required', `${c.caseNumber} is waiting for inspection.`, c, 1 + i)));
  byStatus('ready').forEach((c, i) => ['usr_bashir', 'usr_sagal'].forEach((u) => note(u, 'case_ready', 'Case ready for delivery', `${c.caseNumber} passed QC and is ready to go.`, c, 2 + i, i > 0)));
  byStatus('assigned').forEach((c, i) => {
    const tech = technicians.find((t) => t.id === c.technicianId);
    if (tech?.userId) note(tech.userId, 'case_assigned', 'New case assigned to you', `${c.caseNumber} — ${c.restorationType}, ${c.units} unit(s).`, c, 3 + i);
  });
  byStatus('rework').forEach((c) => {
    const tech = technicians.find((t) => t.id === c.technicianId);
    if (tech?.userId) note(tech.userId, 'qc_failed', 'QC failed — rework required', `${c.caseNumber} was returned by quality control.`, c, 1.5);
  });
  byStatus('correction').forEach((c) => note('usr_amina', 'correction_requested', 'Correction requested', `${c.caseNumber}: Impression file unreadable. Please re-upload the STL.`, c, 4));
  byStatus('delivered').slice(-2).forEach((c) => {
    const cu = users.find((u) => u.role === 'client' && u.clinicId === c.clinicId);
    if (cu) note(cu.id, 'case_delivered', 'Case delivered', `${c.caseNumber} was delivered. Please confirm receipt.`, c, 5);
  });

  return {
    version: DB_VERSION,
    users,
    roles: DEFAULT_ROLES.map((r) => ({ ...r, permissions: [...r.permissions] })),
    clinics,
    doctors,
    patients,
    technicians,
    services: SERVICES.map((s) => ({ ...s })),
    cases,
    history,
    attachments,
    qualityChecks,
    deliveries,
    invoices,
    payments,
    notifications,
    activity: [],
    settings: { ...SEED_SETTINGS },
    sessions: [],
    resetTokens: [],
    deadlineFlags: Object.fromEntries(
      cases
        .filter((c) => c.status === 'delivered' || c.status === 'completed' || c.status === 'cancelled')
        .map((c) => [c.id, { atRisk: true, overdue: true }]),
    ),
    counters: { case: caseSeq, invoice: invSeq, patient: 1024 + PATIENT_NAMES.length, id: idCounter },
  };
}
