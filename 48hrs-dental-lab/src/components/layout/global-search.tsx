import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Command } from 'cmdk';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { Building2, FileText, FolderKanban, Loader2, Search, Stethoscope, UserRound } from 'lucide-react';
import { useDebouncedValue } from '@/hooks/use-debounce';
import { useSearch } from '@/hooks/api/use-lab';
import { useUiStore } from '@/stores/ui-store';
import type { SearchResult } from '@/types/api';

const GROUPS: { type: SearchResult['type']; label: string; icon: typeof Search }[] = [
  { type: 'case', label: 'Cases', icon: FolderKanban },
  { type: 'patient', label: 'Patients', icon: UserRound },
  { type: 'doctor', label: 'Doctors', icon: Stethoscope },
  { type: 'clinic', label: 'Clinics', icon: Building2 },
  { type: 'invoice', label: 'Invoices', icon: FileText },
];

export function SearchTrigger() {
  const setOpen = useUiStore((s) => s.setSearchOpen);
  return (
    <button
      type="button"
      onClick={() => setOpen(true)}
      className="flex h-10 items-center gap-2 rounded-md border border-line bg-gray-50 px-3 text-[13px] text-ink-3 hover:border-line-strong hover:text-ink-2 lg:w-64"
      aria-label="Search cases, patients, doctors, clinics and invoices"
    >
      <Search className="size-4" aria-hidden />
      <span className="hidden truncate whitespace-nowrap lg:inline">Search case, patient, phone…</span>
      <kbd className="ml-auto hidden shrink-0 whitespace-nowrap rounded border border-line bg-card px-1.5 font-mono text-[10.5px] lg:inline">Ctrl K</kbd>
    </button>
  );
}

/** Command palette for global search (Ctrl/Cmd + K). Results link straight to detail pages. */
export function GlobalSearch() {
  const open = useUiStore((s) => s.searchOpen);
  const setOpen = useUiStore((s) => s.setSearchOpen);
  const [q, setQ] = useState('');
  const term = useDebouncedValue(q.trim(), 250);
  const { data, isFetching } = useSearch(term);
  const navigate = useNavigate();

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setOpen(!useUiStore.getState().searchOpen);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [setOpen]);

  useEffect(() => {
    if (!open) setQ('');
  }, [open]);

  const go = (r: SearchResult) => {
    setOpen(false);
    navigate(r.href);
  };

  return (
    <DialogPrimitive.Root open={open} onOpenChange={setOpen}>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-navy-900/55" />
        <DialogPrimitive.Content className="fixed top-[10vh] left-1/2 z-50 w-[calc(100vw-24px)] max-w-[600px] -translate-x-1/2 overflow-hidden rounded-lg bg-card shadow-lg focus:outline-none">
          <DialogPrimitive.Title className="sr-only">Global search</DialogPrimitive.Title>
          <DialogPrimitive.Description className="sr-only">Search cases, patients, doctors, clinics, phone numbers and invoices.</DialogPrimitive.Description>
          <Command shouldFilter={false} loop label="Global search">
            <div className="flex items-center gap-2 border-b border-line px-4">
              <Search className="size-4 text-ink-3" aria-hidden />
              <Command.Input value={q} onValueChange={setQ} placeholder="Case ID, patient, doctor, clinic, phone or invoice…" className="h-14 w-full bg-transparent text-[15px] outline-none placeholder:text-gray-400" autoFocus />
              {isFetching && <Loader2 className="size-4 animate-spin text-ink-3" aria-hidden />}
            </div>
            <Command.List className="max-h-[60vh] overflow-y-auto p-2">
              {term.length < 2 ? (
                <p className="px-3 py-8 text-center text-[13px] text-ink-3">Type at least 2 characters to search.</p>
              ) : (
                <>
                  {!isFetching && <Command.Empty className="px-3 py-8 text-center text-[13px] text-ink-3">No results for “{term}”.</Command.Empty>}
                  {GROUPS.map((g) => {
                    const items = (data ?? []).filter((r) => r.type === g.type);
                    if (!items.length) return null;
                    return (
                      <Command.Group key={g.type} heading={g.label} className="[&_[cmdk-group-heading]]:px-2.5 [&_[cmdk-group-heading]]:pt-2 [&_[cmdk-group-heading]]:pb-1 [&_[cmdk-group-heading]]:text-[11px] [&_[cmdk-group-heading]]:font-bold [&_[cmdk-group-heading]]:tracking-[.12em] [&_[cmdk-group-heading]]:text-ink-3 [&_[cmdk-group-heading]]:uppercase">
                        {items.map((r) => (
                          <Command.Item key={`${r.type}-${r.id}`} value={`${r.type}-${r.id}`} onSelect={() => go(r)} className="flex cursor-pointer items-center gap-3 rounded-md px-2.5 py-2 data-[selected=true]:bg-navy-50">
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-navy-50 text-brand">
                              <g.icon className="size-4" aria-hidden />
                            </span>
                            <span className="flex min-w-0 flex-col">
                              <span className={r.type === 'case' || r.type === 'invoice' ? 'font-mono text-[13px] font-bold text-ink' : 'text-sm font-semibold text-ink'}>{r.title}</span>
                              <span className="truncate text-xs text-ink-3">{r.subtitle}</span>
                            </span>
                          </Command.Item>
                        ))}
                      </Command.Group>
                    );
                  })}
                </>
              )}
            </Command.List>
          </Command>
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  );
}
