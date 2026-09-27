import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { PageMeta } from '@/types/api';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/input';

function pageWindow(page: number, last: number): (number | 'gap')[] {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
  const set = new Set([1, last, page - 1, page, page + 1].filter((p) => p >= 1 && p <= last));
  const sorted = [...set].sort((a, b) => a - b);
  const out: (number | 'gap')[] = [];
  sorted.forEach((p, i) => {
    if (i && p - sorted[i - 1] > 1) out.push('gap');
    out.push(p);
  });
  return out;
}

export function Pagination({ meta, onPageChange, onPerPageChange, perPageOptions = [10, 20, 50, 100] }: { meta: PageMeta; onPageChange: (p: number) => void; onPerPageChange?: (n: number) => void; perPageOptions?: number[] }) {
  const from = meta.total === 0 ? 0 : (meta.page - 1) * meta.perPage + 1;
  const to = Math.min(meta.total, meta.page * meta.perPage);
  return (
    <nav className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3" aria-label="Pagination">
      <div className="flex items-center gap-3 text-[13px] text-ink-2">
        <span className="tabular">
          Showing <b className="text-ink">{from}–{to}</b> of <b className="text-ink">{meta.total}</b>
        </span>
        {onPerPageChange && (
          <label className="hidden items-center gap-2 sm:flex">
            <span className="sr-only">Rows per page</span>
            <NativeSelect value={meta.perPage} onChange={(e) => onPerPageChange(Number(e.target.value))} className="h-8 w-[112px] text-[13px]">
              {perPageOptions.map((n) => (
                <option key={n} value={n}>{n} / page</option>
              ))}
            </NativeSelect>
          </label>
        )}
      </div>
      <div className="flex items-center gap-1">
        <Button variant="outline" size="icon-sm" onClick={() => onPageChange(meta.page - 1)} disabled={meta.page <= 1} aria-label="Previous page">
          <ChevronLeft />
        </Button>
        {pageWindow(meta.page, meta.lastPage).map((p, i) =>
          p === 'gap' ? (
            <span key={`g${i}`} className="px-1 text-ink-3" aria-hidden>…</span>
          ) : (
            <Button
              key={p}
              variant={p === meta.page ? 'primary' : 'ghost'}
              size="icon-sm"
              className="hidden font-mono text-xs sm:inline-flex"
              onClick={() => onPageChange(p)}
              aria-label={`Page ${p}`}
              aria-current={p === meta.page ? 'page' : undefined}
            >
              {p}
            </Button>
          ),
        )}
        <span className="px-2 font-mono text-xs text-ink-2 sm:hidden">{meta.page} / {meta.lastPage}</span>
        <Button variant="outline" size="icon-sm" onClick={() => onPageChange(meta.page + 1)} disabled={meta.page >= meta.lastPage} aria-label="Next page">
          <ChevronRight />
        </Button>
      </div>
    </nav>
  );
}
