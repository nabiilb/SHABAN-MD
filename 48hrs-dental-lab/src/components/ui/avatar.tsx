import { cn } from '@/lib/cn';
import { initials } from '@/utils/format';

export function Avatar({ name, className, size = 'md' }: { name: string; className?: string; size?: 'sm' | 'md' | 'lg' }) {
  const sizes = { sm: 'size-8 text-[11px]', md: 'size-10 text-[13px]', lg: 'size-12 text-sm' };
  return (
    <span aria-hidden className={cn('inline-flex shrink-0 items-center justify-center rounded-full bg-navy-100 font-bold text-navy-700', sizes[size], className)}>
      {initials(name)}
    </span>
  );
}
