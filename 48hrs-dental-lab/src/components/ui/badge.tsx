import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';
import type { Tone } from '@/lib/workflow';

const tones: Record<Tone, string> = {
  info: 'bg-info-bg text-info',
  success: 'bg-success-bg text-success',
  warning: 'bg-warning-bg text-warning',
  danger: 'bg-danger-bg text-danger',
  neutral: 'bg-gray-100 text-gray-600',
  navy: 'bg-navy-900 text-white',
};

export const toneDot: Record<Tone, string> = {
  info: 'bg-info',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  neutral: 'bg-gray-400',
  navy: 'bg-navy-900',
};

export function Badge({ tone = 'neutral', dot, className, children, ...props }: HTMLAttributes<HTMLSpanElement> & { tone?: Tone; dot?: boolean }) {
  return (
    <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11.5px] leading-none font-bold tracking-[.02em] whitespace-nowrap', tones[tone], className)} {...props}>
      {dot && <span className={cn('size-1.5 rounded-full', toneDot[tone])} aria-hidden />}
      {children}
    </span>
  );
}
