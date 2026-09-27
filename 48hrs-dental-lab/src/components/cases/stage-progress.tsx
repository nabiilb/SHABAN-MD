import { cn } from '@/lib/cn';
import { STATUS_META, WORKFLOW_STAGES } from '@/lib/workflow';
import type { CaseStatus } from '@/types/models';

/** Compact "3/7 In Production" with segmented progress, for table rows. */
export function StageMini({ status }: { status: CaseStatus }) {
  const stage = STATUS_META[status].stage;
  if (stage < 0) return <span className="text-xs text-ink-3">{status === 'cancelled' || status === 'rejected' ? '—' : 'Pre-intake'}</span>;
  return (
    <div className="flex min-w-[112px] flex-col gap-1">
      <span className="text-[12.5px] font-semibold whitespace-nowrap text-ink">{WORKFLOW_STAGES[stage].label}</span>
      <span className="flex gap-0.5" aria-label={`Stage ${stage + 1} of ${WORKFLOW_STAGES.length}`}>
        {WORKFLOW_STAGES.map((s, i) => (
          <span key={s.key} className={cn('h-1 flex-1 rounded-full', i <= stage ? (status === 'rework' ? 'bg-danger' : 'bg-brand') : 'bg-gray-200')} />
        ))}
      </span>
    </div>
  );
}
