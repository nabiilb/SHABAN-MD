import type {
  ActivityLogEntry,
  AppNotification,
  CaseAttachment,
  CaseStatusHistory,
  Clinic,
  Delivery,
  Doctor,
  Invoice,
  LabCase,
  LabService,
  LabSettings,
  Patient,
  Payment,
  QualityCheck,
  Role,
  Technician,
  User,
} from '@/types/models';
import { buildSeed } from './seed';

export interface MockUser extends User {
  passwordSalt: string;
  passwordHash: string;
}

export interface MockSession {
  token: string;
  userId: string;
  expiresAt: string;
}

export interface MockDatabase {
  version: number;
  users: MockUser[];
  roles: Role[];
  clinics: Clinic[];
  doctors: Doctor[];
  patients: Patient[];
  technicians: Technician[];
  services: LabService[];
  cases: LabCase[];
  history: CaseStatusHistory[];
  attachments: CaseAttachment[];
  qualityChecks: QualityCheck[];
  deliveries: Delivery[];
  /** Stored without derived fields; paid/remaining/status are computed on read. */
  invoices: Omit<Invoice, 'paid' | 'remaining' | 'status'>[];
  payments: Payment[];
  notifications: AppNotification[];
  activity: ActivityLogEntry[];
  settings: LabSettings;
  sessions: MockSession[];
  resetTokens: { token: string; email: string; expiresAt: string }[];
  deadlineFlags: Record<string, { atRisk?: boolean; overdue?: boolean }>;
  counters: { case: number; invoice: number; patient: number; id: number };
}

const STORAGE_KEY = '48hrs.mockdb';
export const DB_VERSION = 3;

let db: MockDatabase | null = null;

function load(): MockDatabase | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as MockDatabase;
    return parsed.version === DB_VERSION ? parsed : null;
  } catch {
    return null;
  }
}

export function getDb(): MockDatabase {
  if (!db) {
    db = load() ?? buildSeed(Date.now());
    persist();
  }
  return db;
}

export function persist() {
  if (!db) return;
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(db));
  } catch {
    /* quota exceeded or storage disabled — keep working in memory */
  }
}

/** Restores the seeded dataset (Settings → Demo data, and tests). */
export function resetDb(now = Date.now()) {
  const sessions = db?.sessions ?? [];
  db = buildSeed(now);
  db.sessions = sessions.filter((s) => db!.users.some((u) => u.id === s.userId));
  persist();
  return db;
}

export function nextId(prefix: string) {
  const d = getDb();
  d.counters.id += 1;
  return `${prefix}_${d.counters.id.toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}
