import { Clock } from 'lucide-react';
import { cn } from '@/lib/cn';
import { formatDuration, formatRemaining, type SlaInfo, type SlaState } from '@/lib/sla';
import type { LabCase } from '@/types/models';
import { formatDateTime } from '@/utils/format';
import { useSla } from '@/hooks/use-sla';
import { ProgressBar } from '@/components/ui/feedback';

type SlaCase = Pick<LabCase, 'status' | 'receivedAt' | 'dueAt' | 'deliveredAt'>;

/** Colours follow the prototype: green on track, amber attention, red critical, navy overdue. */
const STYLE: Record<SlaState, { box: string; dot: string; bar: 'success' | 'warning' | 'danger' | 'navy' | 'info'; pulse?: boolean }> = {
  on_track: { box: 'bg-success-bg text-success', dot: 'bg-success', bar: 'success' },
  at_risk: { box: 'bg-warning-bg text-warning', dot: 'bg-warning', bar: 'warning' },
  critical: { box: 'bg-danger-bg text-danger', dot: 'bg-danger', bar: 'danger', pulse: true },
  overdue: { box: 'bg-navy-900 text-white', dot: 'bg-white', bar: 'navy', pulse: true },
  met: { box: 'bg-success-bg text-success', dot: 'bg-success', bar: 'success' },
  late: { box: 'bg-danger-bg text-danger', dot: 'bg-danger', bar: 'danger' },
  not_started: { box: 'bg-gray-100 text-gray-600', dot: 'bg-gray-400', bar: 'info' },
  stopped: { box: 'bg-gray-100 text-gray-600', dot: 'bg-gray-400', bar: 'info' },
};

function timeText(info: SlaInfo) {
  if (info.state === 'met' || info.state === 'late') return info.turnaroundMs !== null ? formatDuration(info.turnaroundMs) : '—';
  if (info.remainingMs === null) return '—';
  return `${info.remainingMs < 0 ? '−' : ''}${formatDuration(info.remainingMs)}`;
}

const LABEL: Record<SlaState, string> = {
  on_track: 'ON TRACK',
  at_risk: 'AT RISK',
  critical: 'CRITICAL',
  overdue: 'OVERDUE',
  met: 'DELIVERED WITHIN SLA',
  late: 'DELIVERED LATE',
  not_started: 'SLA NOT STARTED',
  stopped: 'STOPPED',
};

/** Compact bar used in case cards (prototype "● 31h 24m ON TRACK"). */
export function SlaBar({ c, className }: { c: SlaCase; className?: string }) {
  const info = useSla(c);
  const s = STYLE[info.state];
  return (
    <div className={cn('flex items-center gap-2.5 rounded-md px-3 py-2', s.box, className)} aria-label={`Deadline: ${formatRemaining(info)}`}>
      <span className={cn('size-2 shrink-0 rounded-full', s.dot, s.pulse && 'animate-sla-pulse')} aria-hidden />
      <span className="font-mono text-sm font-bold tabular">{timeText(info)}</span>
      <span className="text-[11.5px] font-bold tracking-[.08em]">{LABEL[info.state]}</span>
    </div>
  );
}

/** Table cell: remaining time + state, due date underneath. */
export function SlaCell({ c }: { c: SlaCase }) {
  const info = useSla(c);
  const s = STYLE[info.state];
  if (info.state === 'not_started' || info.state === 'stopped') return <span className="text-xs text-ink-3">{info.label}</span>;
  return (
    <div className="flex flex-col gap-1">
      <span className={cn('inline-flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 font-mono text-[12px] font-bold whitespace-nowrap tabular', s.box)}>
        <span className={cn('size-1.5 rounded-full', s.dot, s.pulse && 'animate-sla-pulse')} aria-hidden />
        {info.state === 'overdue' ? 'OVERDUE' : timeText(info)}
      </span>
      <span className="text-[11.5px] whitespace-nowrap text-ink-3">{info.dueAt ? formatDateTime(info.dueAt) : ''}</span>
    </div>
  );
}

/** Large countdown for the case header, ticking every second. */
export function SlaCountdown({ c }: { c: SlaCase }) {
  const info = useSla(c, 1000);
  const s = STYLE[info.state];
  const h = info.remainingMs !== null ? Math.floor(Math.abs(info.remainingMs) / 3_600_000) : 0;
  const m = info.remainingMs !== null ? Math.floor((Math.abs(info.remainingMs) % 3_600_000) / 60_000) : 0;
  const sec = info.remainingMs !== null ? Math.floor((Math.abs(info.remainingMs) % 60_000) / 1000) : 0;
  const running = info.state === 'on_track' || info.state === 'at_risk' || info.state === 'critical' || info.state === 'overdue';

  return (
    <div className={cn('flex flex-col gap-3 rounded-md px-4 py-3.5', s.box)}>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <Clock className="size-4" aria-hidden />
        <span className="text-[11.5px] font-bold tracking-[.1em]">{LABEL[info.state]}</span>
        {info.dueAt && <span className="ml-auto text-xs">Due {formatDateTime(info.dueAt)}</span>}
      </div>
      <div className="font-mono text-[28px] leading-none font-bold tabular" role="timer" aria-live="off">
        {running ? (
          <>
            {info.state === 'overdue' && '−'}
            {h}h {String(m).padStart(2, '0')}m <span className="text-lg">{String(sec).padStart(2, '0')}s</span>
          </>
        ) : (
          timeText(info)
        )}
      </div>
      <span className="text-xs font-semibold">
        {info.state === 'not_started' ? 'The 48-hour countdown starts when Reception accepts the case.' : formatRemaining(info)}
      </span>
      {info.state !== 'not_started' && info.state !== 'stopped' && <ProgressBar value={info.progress} tone={s.bar} label="Share of the 48-hour window used" className="bg-black/10" />}
    </div>
  );
}
