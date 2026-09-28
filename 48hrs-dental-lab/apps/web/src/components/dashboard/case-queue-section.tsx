import { Link } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { useCases } from '@/hooks/api/use-cases';
import type { CaseListParams } from '@48hrs/shared/types';
import { CaseCard, CaseCardGrid } from '@/components/cases/case-card';
import { EmptyState, Skeleton } from '@/components/ui/feedback';

/** A titled queue of case cards (prototype sections like "WAITING FOR ASSIGNMENT"). */
import { QueryError } from '@/components/ui/query-error';
export function CaseQueueSection({ title, subtitle, params, emptyText, limit = 6, viewAllHref }: { title: string; subtitle?: string; params: CaseListParams; emptyText: string; limit?: number; viewAllHref?: string }) {
  const { data, isLoading, error, refetch } = useCases({ perPage: limit, sort: 'dueAt', dir: 'asc', ...params }, { refetchInterval: 60_000 });
  const total = data?.meta.total ?? 0;
  return (
    <section className="flex flex-col gap-3" aria-labelledby={`q-${title}`}>
      <div className="flex flex-wrap items-baseline gap-x-2.5 gap-y-1">
        <h2 id={`q-${title}`} className="text-sm font-extrabold tracking-[.14em] text-ink uppercase">{title}</h2>
        {subtitle && <span className="text-[13px] text-ink-2">{subtitle}</span>}
        {total > 0 && <span className="rounded-full bg-gray-100 px-2 py-0.5 font-mono text-[11px] font-bold text-ink-2">{total}</span>}
        {viewAllHref && total > limit && (
          <Link to={viewAllHref} className="ml-auto inline-flex items-center gap-1 text-[13px] font-semibold text-navy-500 hover:underline">
            View all <ArrowRight className="size-3.5" />
          </Link>
        )}
      </div>
      {error ? (
        <QueryError error={error} onRetry={() => void refetch()} />
      ) : isLoading ? (
        <CaseCardGrid>{[0, 1, 2].map((i) => <Skeleton key={i} className="h-60" />)}</CaseCardGrid>
      ) : !data?.data.length ? (
        <EmptyState title={emptyText} />
      ) : (
        <CaseCardGrid>
          {data.data.map((c) => <CaseCard key={c.id} c={c} />)}
        </CaseCardGrid>
      )}
    </section>
  );
}
