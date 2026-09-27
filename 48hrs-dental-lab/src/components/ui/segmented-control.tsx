import { useRef, type KeyboardEvent } from 'react';
import { cn } from '@/lib/cn';

export interface SegmentOption<T extends string> {
  value: T;
  label: string;
  count?: number;
}

/**
 * Single-choice view switcher (e.g. All / Open / Delivered) with radio-group
 * semantics. Use Tabs only when each option owns a content panel.
 */
export function SegmentedControl<T extends string>({ value, onChange, options, label }: { value: T; onChange: (v: T) => void; options: SegmentOption<T>[]; label: string }) {
  const refs = useRef<(HTMLButtonElement | null)[]>([]);
  const onKey = (e: KeyboardEvent, i: number) => {
    const dir = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0;
    if (!dir) return;
    e.preventDefault();
    const next = (i + dir + options.length) % options.length;
    onChange(options[next].value);
    refs.current[next]?.focus();
  };
  return (
    <div role="radiogroup" aria-label={label} className="inline-flex min-w-max gap-1 rounded-md border border-line bg-card p-1 shadow-xs">
      {options.map((o, i) => {
        const on = o.value === value;
        return (
          <button
            key={o.value}
            ref={(el) => {
              refs.current[i] = el;
            }}
            type="button"
            role="radio"
            aria-checked={on}
            tabIndex={on ? 0 : -1}
            onKeyDown={(e) => onKey(e, i)}
            onClick={() => onChange(o.value)}
            className={cn('inline-flex items-center gap-2 rounded-sm px-3 py-1.5 text-[13px] font-semibold transition-colors', on ? 'bg-brand text-white' : 'text-ink-2 hover:text-ink')}
          >
            {o.label}
            {o.count !== undefined && <span className="rounded-full bg-black/10 px-1.5 py-0.5 font-mono text-[11px] leading-none">{o.count}</span>}
          </button>
        );
      })}
    </div>
  );
}
