import { useMemo } from 'react';
import { DEFAULT_SLA_CONFIG, getSlaInfo, type SlaConfig } from '@48hrs/shared/sla';
import type { LabCase } from '@48hrs/shared/types';
import { useSettings } from './api/use-admin';
import { useNow } from './use-now';

export function useSlaConfig(): SlaConfig {
  const { data } = useSettings();
  return useMemo(
    () => (data ? { slaHours: data.slaHours, atRiskHours: data.atRiskHours, criticalHours: data.criticalHours } : DEFAULT_SLA_CONFIG),
    [data],
  );
}

/** Live SLA state for a case, recomputed from real timestamps on a shared clock. */
export function useSla(c: Pick<LabCase, 'status' | 'receivedAt' | 'dueAt' | 'deliveredAt'>, tickMs = 30_000) {
  const now = useNow(tickMs);
  const config = useSlaConfig();
  return getSlaInfo(c, now, config);
}
