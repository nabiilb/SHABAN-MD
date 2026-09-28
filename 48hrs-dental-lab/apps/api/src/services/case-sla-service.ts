/**
 * CaseSlaService — the server is the source of truth for the 48-hour clock.
 * received_at is always server time; due_at = received_at + the configured SLA
 * hours; states come from the shared getSlaInfo() evaluated at server time.
 */
import { computeDueAt, getSlaInfo, HOUR_MS, type SlaConfig, type SlaInfo } from '@48hrs/shared/sla';
import type { LabCase, SlaFilter } from '@48hrs/shared/types';
import { IN_LAB_STATUSES } from '@48hrs/shared/workflow';
import type { Prisma } from '../generated/prisma/client.ts';
import type { DbOrTx } from '../lib/prisma.ts';
import { readLabSettings, slaConfigOf } from '../repositories/settings-repository.ts';
import { labDay, labDayEnd } from '../utils/dates.ts';

type SlaFields = Pick<LabCase, 'status' | 'receivedAt' | 'dueAt' | 'deliveredAt'>;

export type SlaOutcome = 'on_time' | 'at_risk' | 'overdue' | 'completed_on_time' | 'completed_late' | 'not_started' | 'stopped';

export const caseSlaService = {
  async config(db: DbOrTx): Promise<SlaConfig> {
    return slaConfigOf(await readLabSettings(db));
  },

  /** Deadline for a case received now (or at `receivedAt`), unless an explicit due date was agreed. */
  dueAt(receivedAt: Date, cfg: SlaConfig, override?: Date | null): Date {
    return override ?? computeDueAt(receivedAt, cfg.slaHours);
  },

  info(c: SlaFields, cfg: SlaConfig, now = Date.now()): SlaInfo {
    return getSlaInfo(c, now, cfg);
  },

  /** The five business outcomes: on time, at risk, overdue, completed on time, completed late. */
  outcome(c: SlaFields, cfg: SlaConfig, now = Date.now()): SlaOutcome {
    const s = getSlaInfo(c, now, cfg).state;
    switch (s) {
      case 'on_track':
        return 'on_time';
      case 'at_risk':
      case 'critical':
        return 'at_risk';
      case 'overdue':
        return 'overdue';
      case 'met':
        return 'completed_on_time';
      case 'late':
        return 'completed_late';
      default:
        return s;
    }
  },

  /** Cases whose clock is running (in a lab status with a deadline). */
  runningWhere(): Prisma.DentalCaseWhereInput {
    return { status: { in: IN_LAB_STATUSES }, dueAt: { not: null } };
  },

  overdueWhere(now = Date.now()): Prisma.DentalCaseWhereInput {
    return { ...this.runningWhere(), dueAt: { lte: new Date(now) } };
  },

  /** SQL equivalent of the SLA list filters (same thresholds as getSlaInfo). */
  filterWhere(filter: SlaFilter, cfg: SlaConfig, now = Date.now()): Prisma.DentalCaseWhereInput {
    const at = new Date(now);
    const riskEdge = new Date(now + cfg.atRiskHours * HOUR_MS);
    const running = { status: { in: IN_LAB_STATUSES } };
    switch (filter) {
      case 'overdue':
        return { ...running, dueAt: { lte: at } };
      case 'at_risk':
        return { ...running, dueAt: { gt: at, lte: riskEdge } };
      case 'on_track':
        return { ...running, dueAt: { gt: riskEdge } };
      case 'due_today':
        return { ...running, dueAt: { gt: at, lt: labDayEnd(labDay(now)) } };
    }
  },
};
