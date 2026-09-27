import type { ReactNode } from 'react';
import * as TabsPrimitive from '@radix-ui/react-tabs';
import { cn } from '@/lib/cn';

export const Tabs = TabsPrimitive.Root;
export const TabsContent = TabsPrimitive.Content;

export function TabsList({ children, className, label }: { children: ReactNode; className?: string; label?: string }) {
  return (
    <div className="-mx-1 overflow-x-auto px-1">
      <TabsPrimitive.List aria-label={label} className={cn('inline-flex min-w-max gap-1 rounded-md border border-line bg-card p-1 shadow-xs', className)}>
        {children}
      </TabsPrimitive.List>
    </div>
  );
}

export function TabsTrigger({ value, children, count }: { value: string; children: ReactNode; count?: number }) {
  return (
    <TabsPrimitive.Trigger
      value={value}
      className="inline-flex items-center gap-2 rounded-sm px-3 py-1.5 text-[13px] font-semibold text-ink-2 transition-colors hover:text-ink data-[state=active]:bg-brand data-[state=active]:text-white"
    >
      {children}
      {count !== undefined && (
        <span className="rounded-full bg-black/10 px-1.5 py-0.5 font-mono text-[11px] leading-none">{count}</span>
      )}
    </TabsPrimitive.Trigger>
  );
}
