import type { CaseStatus, LabCase } from './models';

export const HOUR_MS = 3_600_000;
export const DEFAULT_SLA_HOURS = 48;

export interface SlaConfig {
  slaHours: number;
  atRiskHours: number;
  criticalHours: number;
}

export const DEFAULT_SLA_CONFIG: SlaConfig = { slaHours: DEFAULT_SLA_HOURS, atRiskHours: 12, criticalHours: 4 };

export type SlaState =
  | 'not_started'
  | 'on_track'
  | 'at_risk'
  | 'critical'
  | 'overdue'
  | 'met'
  | 'late'
  | 'stopped';

export interface SlaInfo {
  state: SlaState;
  label: string;
  /** Milliseconds until due (negative when overdue). Null when the clock is not running. */
  remainingMs: number | null;
  /** Elapsed share of the SLA window, 0–1 (clamped). */
  progress: number;
  /** Total turnaround for finished cases. */
  turnaroundMs: number | null;
  dueAt: Date | null;
}

/** due_at = received_at + SLA hours. */
export function computeDueAt(receivedAt: Date | string, slaHours = DEFAULT_SLA_HOURS): Date {
  const start = typeof receivedAt === 'string' ? new Date(receivedAt) : receivedAt;
  return new Date(start.getTime() + slaHours * HOUR_MS);
}

const LABELS: Record<SlaState, string> = {
  not_started: 'Not started',
  on_track: 'On track',
  at_risk: 'At risk',
  critical: 'Critical',
  overdue: 'Overdue',
  met: 'Delivered on time',
  late: 'Delivered late',
  stopped: 'Stopped',
};

const STOPPED: CaseStatus[] = ['cancelled', 'rejected'];

type SlaCase = Pick<LabCase, 'status' | 'receivedAt' | 'dueAt' | 'deliveredAt'>;

/** Derives the SLA state purely from real timestamps and "now". */
export function getSlaInfo(c: SlaCase, now: number = Date.now(), config: SlaConfig = DEFAULT_SLA_CONFIG): SlaInfo {
  const received = c.receivedAt ? new Date(c.receivedAt).getTime() : null;
  const due = c.dueAt ? new Date(c.dueAt).getTime() : received !== null ? received + config.slaHours * HOUR_MS : null;
  const dueAt = due !== null ? new Date(due) : null;

  if (received === null || due === null) {
    return { state: STOPPED.includes(c.status) ? 'stopped' : 'not_started', label: STOPPED.includes(c.status) ? LABELS.stopped : LABELS.not_started, remainingMs: null, progress: 0, turnaroundMs: null, dueAt };
  }

  const window = Math.max(due - received, 1);

  if (c.deliveredAt) {
    const delivered = new Date(c.deliveredAt).getTime();
    const state: SlaState = delivered <= due ? 'met' : 'late';
    return { state, label: LABELS[state], remainingMs: due - delivered, progress: Math.min(1, (delivered - received) / window), turnaroundMs: delivered - received, dueAt };
  }

  if (STOPPED.includes(c.status)) {
    return { state: 'stopped', label: LABELS.stopped, remainingMs: null, progress: 0, turnaroundMs: null, dueAt };
  }

  const remainingMs = due - now;
  const progress = Math.min(1, Math.max(0, (now - received) / window));
  let state: SlaState = 'on_track';
  if (remainingMs <= 0) state = 'overdue';
  else if (remainingMs <= config.criticalHours * HOUR_MS) state = 'critical';
  else if (remainingMs <= config.atRiskHours * HOUR_MS) state = 'at_risk';

  return { state, label: LABELS[state], remainingMs, progress, turnaroundMs: null, dueAt };
}

/** "31h 24m" — hours can exceed 24 on purpose, matching the 48-hour mental model. */
export function formatDuration(ms: number): string {
  const abs = Math.abs(ms);
  const h = Math.floor(abs / HOUR_MS);
  const m = Math.floor((abs % HOUR_MS) / 60_000);
  return `${h}h ${String(m).padStart(2, '0')}m`;
}

export function formatRemaining(info: SlaInfo): string {
  if (info.state === 'met' || info.state === 'late') return info.turnaroundMs !== null ? `${formatDuration(info.turnaroundMs)} turnaround` : info.label;
  if (info.remainingMs === null) return '—';
  if (info.remainingMs <= 0) return `Overdue by ${formatDuration(info.remainingMs)}`;
  return `${formatDuration(info.remainingMs)} remaining`;
}

type DeliveredCase = Pick<LabCase, 'receivedAt' | 'dueAt' | 'deliveredAt' | 'status'>;

/** true = delivered by due_at, false = delivered late, null = not delivered / no deadline. */
export function deliveredOnTime(c: DeliveredCase): boolean | null {
  const s = getSlaInfo(c).state;
  return s === 'met' ? true : s === 'late' ? false : null;
}

/** Share of delivered cases that met their deadline, or null when none were delivered. */
export function onTimeRate(cases: DeliveredCase[]): number | null {
  const measured = cases.map(deliveredOnTime).filter((x): x is boolean => x !== null);
  return measured.length ? measured.filter(Boolean).length / measured.length : null;
}
