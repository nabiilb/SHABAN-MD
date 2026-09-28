import type { ReactNode } from 'react';
import { AlertTriangle, Inbox, Loader2, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/cn';
import { Button } from './button';

export function Spinner({ className, label = 'Loading' }: { className?: string; label?: string }) {
  return (
    <span role="status" className={cn('inline-flex items-center gap-2 text-ink-3', className)}>
      <Loader2 className="size-4 animate-spin" aria-hidden />
      <span className="sr-only">{label}</span>
    </span>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('animate-pulse rounded-md bg-gray-100', className)} aria-hidden />;
}

export function PageLoader({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="flex min-h-[40vh] items-center justify-center" role="status" aria-live="polite">
      <div className="flex items-center gap-3 text-sm text-ink-3">
        <Loader2 className="size-5 animate-spin text-brand" aria-hidden />
        {label}
      </div>
    </div>
  );
}

export function EmptyState({ title, description, icon, action, className }: { title: string; description?: string; icon?: ReactNode; action?: ReactNode; className?: string }) {
  return (
    <div className={cn('flex flex-col items-center gap-2 rounded-md border border-dashed border-line-strong bg-gray-50 px-6 py-10 text-center', className)}>
      <span className="mb-1 flex size-10 items-center justify-center rounded-full bg-navy-50 text-brand [&_svg]:size-5">{icon ?? <Inbox />}</span>
      <p className="text-sm font-bold text-ink">{title}</p>
      {description && <p className="max-w-sm text-[13px] text-ink-3">{description}</p>}
      {action && <div className="mt-2">{action}</div>}
    </div>
  );
}

export function ErrorState({ title = 'Could not load this data', message, onRetry, className }: { title?: string; message?: string; onRetry?: () => void; className?: string }) {
  return (
    <div role="alert" className={cn('flex flex-col items-center gap-2 rounded-md border border-danger-bg bg-card px-6 py-10 text-center', className)}>
      <span className="mb-1 flex size-10 items-center justify-center rounded-full bg-danger-bg text-danger">
        <AlertTriangle className="size-5" aria-hidden />
      </span>
      <p className="text-sm font-bold text-ink">{title}</p>
      {message && <p className="max-w-md text-[13px] text-ink-3">{message}</p>}
      {onRetry && (
        <Button variant="outline" size="sm" onClick={onRetry} className="mt-2">
          <RefreshCw /> Try again
        </Button>
      )}
    </div>
  );
}

export function Alert({ tone = 'info', children, className, title }: { tone?: 'info' | 'warning' | 'danger' | 'success'; title?: string; children: ReactNode; className?: string }) {
  const tones = {
    info: 'bg-info-bg text-info',
    warning: 'bg-warning-bg text-warning',
    danger: 'bg-danger-bg text-danger',
    success: 'bg-success-bg text-success',
  };
  return (
    <div role={tone === 'danger' ? 'alert' : 'status'} className={cn('rounded-md px-3.5 py-3 text-[13px] leading-relaxed', tones[tone], className)}>
      {title && <p className="font-bold">{title}</p>}
      {children}
    </div>
  );
}

export function ProgressBar({ value, tone = 'info', className, label }: { value: number; tone?: 'info' | 'success' | 'warning' | 'danger' | 'navy'; className?: string; label?: string }) {
  const tones = { info: 'bg-brand', success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger', navy: 'bg-navy-900' };
  const pct = Math.round(Math.min(1, Math.max(0, value)) * 100);
  return (
    <div className={cn('h-1.5 overflow-hidden rounded-full bg-gray-100', className)} role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} aria-label={label}>
      <div className={cn('h-full rounded-full transition-[width] duration-300', tones[tone])} style={{ width: `${pct}%` }} />
    </div>
  );
}
