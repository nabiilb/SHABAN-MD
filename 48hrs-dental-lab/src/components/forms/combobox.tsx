import { useState, type ReactNode } from 'react';
import { Command } from 'cmdk';
import { Check, ChevronsUpDown, Loader2, X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

export interface ComboOption {
  value: string;
  label: string;
  description?: string;
  disabled?: boolean;
}

interface BaseProps {
  options: ComboOption[];
  placeholder?: string;
  searchPlaceholder?: string;
  emptyText?: string;
  id?: string;
  invalid?: boolean;
  describedBy?: string;
  disabled?: boolean;
  loading?: boolean;
  /** Server-side search: when set, the list is not filtered locally. */
  onSearchChange?: (q: string) => void;
  footer?: ReactNode;
}

function List({ options, selected, onPick, searchPlaceholder, emptyText, loading, onSearchChange, footer }: BaseProps & { selected: string[]; onPick: (v: string) => void }) {
  const [q, setQ] = useState('');
  return (
    <Command shouldFilter={!onSearchChange} className="flex flex-col" loop>
      <div className="flex items-center border-b border-line px-2">
        <Command.Input
          value={q}
          onValueChange={(v) => {
            setQ(v);
            onSearchChange?.(v);
          }}
          placeholder={searchPlaceholder ?? 'Type to search…'}
          className="h-10 w-full bg-transparent px-1 text-sm outline-none placeholder:text-gray-400"
        />
        {loading && <Loader2 className="size-4 animate-spin text-ink-3" aria-hidden />}
      </div>
      <Command.List className="max-h-64 overflow-y-auto p-1">
        <Command.Empty className="px-3 py-6 text-center text-[13px] text-ink-3">{loading ? 'Searching…' : emptyText ?? 'No matches.'}</Command.Empty>
        {options.map((o) => (
          <Command.Item
            key={o.value}
            value={`${o.label} ${o.description ?? ''} ${o.value}`}
            disabled={o.disabled}
            onSelect={() => onPick(o.value)}
            className="flex cursor-pointer items-start gap-2 rounded-sm px-2.5 py-2 text-sm aria-disabled:opacity-50 data-[selected=true]:bg-navy-50"
          >
            <Check className={cn('mt-0.5 size-4 shrink-0 text-brand', selected.includes(o.value) ? 'opacity-100' : 'opacity-0')} aria-hidden />
            <span className="flex min-w-0 flex-col">
              <span className="font-medium text-ink">{o.label}</span>
              {o.description && <span className="truncate text-xs text-ink-3">{o.description}</span>}
            </span>
          </Command.Item>
        ))}
      </Command.List>
      {footer && <div className="border-t border-line p-1">{footer}</div>}
    </Command>
  );
}

const triggerCls =
  'flex min-h-10 w-full items-center justify-between gap-2 rounded-md border border-line-strong bg-card px-3 py-1.5 text-left text-sm transition-colors focus:border-navy-400 focus:ring-3 focus:ring-navy-400/30 focus:outline-none disabled:cursor-not-allowed disabled:bg-gray-100 aria-[invalid=true]:border-danger';

/** Searchable single select (keyboard navigable, screen-reader labelled). */
export function Combobox({ value, onChange, placeholder = 'Select…', selectedLabel, ...props }: BaseProps & { value: string | null | undefined; onChange: (v: string | null) => void; selectedLabel?: string }) {
  const [open, setOpen] = useState(false);
  const current = props.options.find((o) => o.value === value);
  const label = current?.label ?? selectedLabel;
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button type="button" id={props.id} role="combobox" aria-expanded={open} aria-invalid={props.invalid} aria-describedby={props.describedBy} disabled={props.disabled} className={triggerCls}>
          <span className={cn('truncate', !label && 'text-gray-400')}>{label ?? placeholder}</span>
          <ChevronsUpDown className="size-4 shrink-0 text-ink-3" aria-hidden />
        </button>
      </PopoverTrigger>
      <PopoverContent>
        <List
          {...props}
          selected={value ? [value] : []}
          onPick={(v) => {
            onChange(v === value ? null : v);
            setOpen(false);
          }}
        />
      </PopoverContent>
    </Popover>
  );
}

/** Searchable multi select with removable chips. */
export function MultiSelect({ value, onChange, placeholder = 'Select…', ...props }: BaseProps & { value: string[]; onChange: (v: string[]) => void }) {
  const [open, setOpen] = useState(false);
  const chosen = props.options.filter((o) => value.includes(o.value));
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button type="button" id={props.id} role="combobox" aria-expanded={open} aria-invalid={props.invalid} aria-describedby={props.describedBy} disabled={props.disabled} className={triggerCls}>
          <span className="flex min-w-0 flex-wrap gap-1.5">
            {chosen.length === 0 && <span className="text-gray-400">{placeholder}</span>}
            {chosen.map((o) => (
              <span key={o.value} className="inline-flex items-center gap-1 rounded-full border border-navy-100 bg-navy-50 px-2 py-0.5 text-xs font-semibold text-brand">
                {o.label}
                <span
                  role="button"
                  tabIndex={-1}
                  aria-label={`Remove ${o.label}`}
                  onPointerDown={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    onChange(value.filter((v) => v !== o.value));
                  }}
                  className="opacity-60 hover:opacity-100"
                >
                  <X className="size-3" />
                </span>
              </span>
            ))}
          </span>
          <ChevronsUpDown className="size-4 shrink-0 text-ink-3" aria-hidden />
        </button>
      </PopoverTrigger>
      <PopoverContent>
        <List {...props} selected={value} onPick={(v) => onChange(value.includes(v) ? value.filter((x) => x !== v) : [...value, v])} />
      </PopoverContent>
    </Popover>
  );
}
