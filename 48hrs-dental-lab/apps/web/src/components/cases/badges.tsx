import { PAYMENT_STATUS_META, PRIORITY_META } from '@48hrs/shared/constants';
import { STATUS_META } from '@48hrs/shared/workflow';
import type { CasePriority, CaseStatus, PaymentStatus } from '@48hrs/shared/types';
import { Badge } from '@/components/ui/badge';

export function StatusBadge({ status }: { status: CaseStatus }) {
  const m = STATUS_META[status];
  return (
    <Badge tone={m.tone} className="uppercase">
      {m.label}
    </Badge>
  );
}

export function PriorityBadge({ priority, hideNormal }: { priority: CasePriority; hideNormal?: boolean }) {
  if (hideNormal && priority === 'normal') return null;
  const m = PRIORITY_META[priority];
  return (
    <Badge tone={m.tone} dot={priority !== 'normal'}>
      {m.label}
    </Badge>
  );
}

export function PaymentBadge({ status }: { status: PaymentStatus }) {
  const m = PAYMENT_STATUS_META[status];
  return <Badge tone={m.tone}>{m.label}</Badge>;
}
