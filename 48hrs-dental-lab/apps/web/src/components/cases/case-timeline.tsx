import { Check } from 'lucide-react';
import { cn } from '@/lib/cn';
import { formatDuration } from '@48hrs/shared/sla';
import { ROLE_LABELS } from '@48hrs/shared/permissions';
import { STATUS_META, WORKFLOW_STAGES } from '@48hrs/shared/workflow';
import type { CaseStatus, CaseStatusHistory } from '@48hrs/shared/types';
import { formatDateTime } from '@/utils/format';
import { StatusBadge } from './badges';

const STAGE_OF: Partial<Record<CaseStatus, number>> = { received: 0, review: 1, assigned: 2, in_production: 3, quality_control: 4, ready: 5, delivered: 6 };

/** Received → Review → Assigned → In Production → QC → Ready → Delivered, with the time each was first reached. */
export function CaseStepper({ status, history }: { status: CaseStatus; history: CaseStatusHistory[] }) {
  const current = STATUS_META[status].stage;
  const reachedAt = WORKFLOW_STAGES.map((_, i) => history.find((h) => STAGE_OF[h.toStatus] === i)?.createdAt ?? null);
  const stopped = status === 'cancelled' || status === 'rejected';
  return (
    <ol className="grid grid-cols-7 gap-1" aria-label="Case progress">
      {WORKFLOW_STAGES.map((s, i) => {
        const done = !stopped && (i < current || (i === current && (status === 'delivered' || status === 'completed')));
        const active = !stopped && i === current && !done;
        return (
          <li key={s.key} className="flex min-w-0 flex-col items-center gap-1.5 text-center" aria-current={active ? 'step' : undefined}>
            <div className="flex w-full items-center">
              <span className={cn('h-0.5 flex-1', i === 0 ? 'bg-transparent' : done || active ? 'bg-brand' : 'bg-gray-200')} />
              <span
                className={cn(
                  'flex size-7 shrink-0 items-center justify-center rounded-full border-2 font-mono text-[11px] font-bold',
                  done ? 'border-brand bg-brand text-white' : active ? (status === 'rework' ? 'border-danger bg-danger-bg text-danger' : 'border-brand bg-navy-50 text-brand') : 'border-gray-200 bg-card text-ink-3',
                )}
              >
                {done ? <Check className="size-3.5" strokeWidth={3} /> : i + 1}
              </span>
              <span className={cn('h-0.5 flex-1', i === WORKFLOW_STAGES.length - 1 ? 'bg-transparent' : done ? 'bg-brand' : 'bg-gray-200')} />
            </div>
            <span className={cn('text-[11px] leading-tight font-semibold sm:text-xs', done || active ? 'text-ink' : 'text-ink-3')}>{s.label}</span>
            <span className="hidden font-mono text-[10.5px] text-ink-3 md:block">{reachedAt[i] ? formatDateTime(reachedAt[i]).replace(/^\d{2} \w{3} \d{4}, /, '') : '—'}</span>
          </li>
        );
      })}
    </ol>
  );
}

/** Full audit trail: stage, who, when, time spent since the previous step, and notes. */
export function CaseHistoryList({ history }: { history: CaseStatusHistory[] }) {
  if (!history.length) return <p className="text-sm text-ink-3">No history yet.</p>;
  return (
    <ol className="flex flex-col">
      {history.map((h, i) => {
        const prev = history[i - 1];
        const duration = prev ? new Date(h.createdAt).getTime() - new Date(prev.createdAt).getTime() : null;
        return (
          <li key={h.id} className="grid grid-cols-[92px_1fr] gap-3 sm:grid-cols-[132px_1fr]">
            <div className="flex flex-col pt-0.5 text-right">
              <span className="font-mono text-xs text-ink-2">{formatDateTime(h.createdAt).split(', ')[1]}</span>
              <span className="text-[11px] text-ink-3">{formatDateTime(h.createdAt).split(', ')[0]}</span>
            </div>
            <div className="relative flex flex-col gap-1 border-l-2 border-navy-100 pb-4 pl-4">
              <span className="absolute top-1.5 -left-[5px] size-2 rounded-full bg-brand" aria-hidden />
              <div className="flex flex-wrap items-center gap-2">
                <StatusBadge status={h.toStatus} />
                {duration !== null && duration >= 60_000 && <span className="font-mono text-[11px] text-ink-3">+{formatDuration(duration)}</span>}
              </div>
              <span className="text-[13px] text-ink-2">
                {h.userName} <span className="text-ink-3">· {ROLE_LABELS[h.userRole]}</span>
              </span>
              {h.note && <p className="text-[13.5px] leading-snug text-ink">{h.note}</p>}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
