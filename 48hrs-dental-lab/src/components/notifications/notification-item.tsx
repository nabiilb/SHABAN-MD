import { cn } from '@/lib/cn';
import { NOTIFICATION_TONE } from '@/lib/constants';
import type { AppNotification } from '@/types/models';
import { formatDateTime, formatRelativeDay, timeAgo } from '@/utils/format';
import { toneDot } from '@/components/ui/badge';

export function NotificationItem({ n, onOpen, compact }: { n: AppNotification; onOpen: (n: AppNotification) => void; compact?: boolean }) {
  const unread = !n.readAt;
  return (
    <button
      type="button"
      onClick={() => onOpen(n)}
      className={cn('flex w-full gap-3 text-left transition-colors hover:bg-gray-50', compact ? 'px-3 py-2.5' : 'px-4 py-3.5', unread && 'bg-navy-50/50')}
    >
      <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', unread ? toneDot[NOTIFICATION_TONE[n.type]] : 'bg-gray-300')} aria-hidden />
      <span className="flex min-w-0 flex-1 flex-col gap-0.5">
        <span className="flex flex-wrap items-baseline gap-x-2">
          <span className={cn('text-[13px] text-ink', unread ? 'font-bold' : 'font-semibold')}>{n.title}</span>
          {n.caseNumber && <span className="font-mono text-[11.5px] font-bold text-brand">{n.caseNumber}</span>}
        </span>
        <span className="text-[13px] leading-snug text-ink-2">{n.message}</span>
        <span className="font-mono text-[11px] text-ink-3">
          <time dateTime={n.createdAt} title={formatDateTime(n.createdAt)}>
            {timeAgo(n.createdAt)} · {formatRelativeDay(n.createdAt)}
          </time>
          {unread && <span className="sr-only"> · unread</span>}
        </span>
      </span>
    </button>
  );
}
