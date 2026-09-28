import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('rounded-md border border-line bg-card shadow-sm', className)} {...props} />;
}

export function CardHeader({ title, description, actions, className }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; className?: string }) {
  return (
    <div className={cn('flex flex-wrap items-start justify-between gap-3 px-4 pt-4 sm:px-5 sm:pt-5', className)}>
      <div className="flex min-w-0 flex-col gap-1">
        <h2 className="eyebrow">{title}</h2>
        {description && <p className="text-[12.5px] text-ink-2">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function CardBody({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('p-4 sm:p-5', className)} {...props} />;
}

/** Label/value pair used across detail panels ("CLINIC  Smile Dental Clinic"). */
export function Field({ label, children, mono, className }: { label: ReactNode; children: ReactNode; mono?: boolean; className?: string }) {
  return (
    <div className={cn('flex min-w-0 flex-col gap-0.5', className)}>
      <dt className="text-[11px] tracking-[.06em] text-ink-3 uppercase">{label}</dt>
      <dd className={cn('text-sm font-semibold break-words text-ink', mono && 'font-mono')}>{children}</dd>
    </div>
  );
}
