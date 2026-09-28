import { DEFAULT_LAB_SETTINGS } from '@48hrs/shared/constants';
import type { SlaConfig } from '@48hrs/shared/sla';
import type { LabSettings } from '@48hrs/shared/types';
import type { DbOrTx } from '../lib/prisma.ts';

const KEY = 'lab';

/** Lab settings (defaults merged in, so a newly added setting always has a value). */
export async function readLabSettings(db: DbOrTx): Promise<LabSettings> {
  const row = await db.setting.findUnique({ where: { key: KEY } });
  return { ...DEFAULT_LAB_SETTINGS, ...((row?.value as Partial<LabSettings> | null) ?? {}) };
}

export async function writeLabSettings(db: DbOrTx, settings: LabSettings) {
  await db.setting.upsert({ where: { key: KEY }, create: { key: KEY, value: { ...settings } }, update: { value: { ...settings } } });
  return settings;
}

export function slaConfigOf(s: LabSettings): SlaConfig {
  return { slaHours: s.slaHours, atRiskHours: s.atRiskHours, criticalHours: s.criticalHours };
}
