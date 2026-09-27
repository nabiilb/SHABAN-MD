import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/** Row above a page's main content: context on the left, page actions on the right. */
export function PageToolbar({ children, actions, className }: { children?: ReactNode; actions?: ReactNode; className?: string }) {
  if (!children && !actions) return null;
  return (
    <div className={cn('flex flex-wrap items-center justify-between gap-3', className)}>
      <div className="flex min-w-0 flex-wrap items-center gap-2">{children}</div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}
