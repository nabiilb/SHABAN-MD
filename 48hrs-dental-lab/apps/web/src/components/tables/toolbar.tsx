import { useEffect, useState, type ReactNode } from 'react';
import { Search, SlidersHorizontal, X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useDebouncedValue } from '@/hooks/use-debounce';
import { Button } from '@/components/ui/button';
import { Input, NativeSelect } from '@/components/ui/input';

/** Debounced search box: typing updates the URL/query 300 ms after the user stops. */
export function SearchInput({ value, onChange, placeholder = 'Search…', className, label = 'Search' }: { value: string; onChange: (v: string) => void; placeholder?: string; className?: string; label?: string }) {
  const [text, setText] = useState(value);
  const debounced = useDebouncedValue(text, 300);

  useEffect(() => setText(value), [value]);
  useEffect(() => {
    if (debounced !== value) onChange(debounced);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);

  return (
    <div className={cn('relative w-full sm:w-72', className)}>
      <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-3" aria-hidden />
      <Input value={text} onChange={(e) => setText(e.target.value)} placeholder={placeholder} aria-label={label} className="h-9 pr-8 pl-9" type="search" />
      {text && (
        <button type="button" onClick={() => setText('')} className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-1 text-ink-3 hover:text-ink" aria-label="Clear search">
          <X className="size-3.5" />
        </button>
      )}
    </div>
  );
}

export interface FilterOption {
  value: string;
  label: string;
}

export function FilterSelect({ label, value, onChange, options, allLabel = 'All', className }: { label: string; value: string | undefined; onChange: (v: string | undefined) => void; options: FilterOption[]; allLabel?: string; className?: string }) {
  return (
    <label className={cn('w-full sm:w-auto', className)}>
      <span className="sr-only">{label}</span>
      <NativeSelect value={value ?? ''} onChange={(e) => onChange(e.target.value || undefined)} className={cn('h-9 text-[13px] sm:w-44', value && 'border-navy-300 bg-navy-50 font-semibold text-brand')}>
        <option value="">{`${label}: ${allLabel}`}</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </NativeSelect>
    </label>
  );
}

export function DateFilter({ label, value, onChange }: { label: string; value: string | undefined; onChange: (v: string | undefined) => void }) {
  return (
    <label className="flex w-full items-center gap-2 sm:w-auto">
      <span className="shrink-0 text-xs font-semibold text-ink-3">{label}</span>
      <Input type="date" value={value ?? ''} onChange={(e) => onChange(e.target.value || undefined)} className="h-9 text-[13px] sm:w-40" />
    </label>
  );
}

export function ClearFiltersButton({ show, onClear }: { show: boolean; onClear: () => void }) {
  if (!show) return null;
  return (
    <Button variant="ghost" size="sm" onClick={onClear} className="text-ink-2">
      <X /> Clear filters
    </Button>
  );
}

export function ToolbarRow({ children }: { children: ReactNode }) {
  return <div className="flex w-full flex-wrap items-center gap-2">{children}</div>;
}

/** Filters that are always visible on desktop but fold behind a toggle on phones. */
export function CollapsibleFilters({ children, activeCount }: { children: ReactNode; activeCount: number }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button variant="outline" size="sm" className="md:hidden" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        <SlidersHorizontal /> Filters{activeCount > 0 && <span className="rounded-full bg-brand px-1.5 font-mono text-[11px] text-white">{activeCount}</span>}
      </Button>
      <div className={cn('w-full flex-wrap items-center gap-2 md:flex md:w-auto', open ? 'flex' : 'hidden')}>{children}</div>
    </>
  );
}
