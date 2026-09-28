import { cn } from '@/lib/cn';

/** "48HRS / DENTAL LAB" wordmark from the prototype sidebar and login panel. */
export function Wordmark({ size = 'md', className }: { size?: 'md' | 'lg'; className?: string }) {
  return (
    <div className={cn('flex flex-col gap-1', className)}>
      <span className={cn('font-display leading-none font-extrabold text-white', size === 'lg' ? 'text-[38px]' : 'text-[27px]')}>48HRS</span>
      <span className={cn('font-bold tracking-[.3em] text-navy-300', size === 'lg' ? 'text-[11px]' : 'text-[10px]')}>DENTAL LAB</span>
    </div>
  );
}
