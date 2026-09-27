import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { cn } from '@/lib/cn';
import { Skeleton } from '@/components/ui/feedback';

type Emphasis = 'default' | 'warning' | 'danger' | 'success';

const TEXT: Record<Emphasis, string> = { default: 'text-ink', warning: 'text-warning', danger: 'text-danger', success: 'text-success' };

/** Prototype stat tile: small label, large mono figure. Links to the filtered list when `to` is set. */
export function StatCard({ label, value, emphasis = 'default', hint, to, loading, icon }: { label: string; value: ReactNode; emphasis?: Emphasis; hint?: ReactNode; to?: string; loading?: boolean; icon?: ReactNode }) {
  const body = (
    <>
      <span className="flex items-center justify-between gap-2 text-xs font-semibold tracking-[.02em] text-ink-2">
        {label}
        {icon && <span className="text-ink-3 [&_svg]:size-4">{icon}</span>}
      </span>
      {loading ? <Skeleton className="h-[30px] w-16" /> : <span className={cn('font-mono text-[28px] leading-none font-bold tabular sm:text-[30px]', TEXT[emphasis])}>{value}</span>}
      {hint && <span className="text-[11.5px] text-ink-3">{hint}</span>}
    </>
  );
  const cls = 'flex min-w-0 flex-col gap-2 rounded-md border border-line bg-card px-4 py-3.5 shadow-xs';
  return to ? (
    <Link to={to} className={cn(cls, 'transition-colors hover:border-navy-200 hover:bg-navy-50/40')}>
      {body}
    </Link>
  ) : (
    <div className={cls}>{body}</div>
  );
}

export function StatGrid({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn('grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fit,minmax(160px,1fr))]', className)}>{children}</div>;
}
