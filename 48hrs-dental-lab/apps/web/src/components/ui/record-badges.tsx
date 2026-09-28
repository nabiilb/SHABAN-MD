import { Badge } from './badge';

/** Active / inactive record status used by clinics, doctors, technicians, users and services. */
export function ActiveBadge({ active, activeLabel = 'Active', inactiveLabel = 'Inactive' }: { active: boolean; activeLabel?: string; inactiveLabel?: string }) {
  return <Badge tone={active ? 'success' : 'neutral'}>{active ? activeLabel : inactiveLabel}</Badge>;
}
