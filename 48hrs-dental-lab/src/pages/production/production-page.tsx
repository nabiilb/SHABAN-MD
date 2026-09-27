import { useState } from 'react';
import { PERMISSIONS } from '@/lib/permissions';
import { useCases } from '@/hooks/api/use-cases';
import { useTechnicians } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { useDebouncedValue } from '@/hooks/use-debounce';
import { errorMessage } from '@/services/api/errors';
import type { CaseStatus } from '@/types/models';
import { CaseCard } from '@/components/cases/case-card';
import { FilterSelect, SearchInput } from '@/components/tables/toolbar';
import { ErrorState, Skeleton } from '@/components/ui/feedback';

const COLUMNS: { title: string; subtitle: string; statuses: CaseStatus[]; empty: string }[] = [
  { title: 'Waiting for assignment', subtitle: 'Received & in review', statuses: ['received', 'review'], empty: 'Every accepted case is assigned.' },
  { title: 'Assigned', subtitle: 'Not started yet', statuses: ['assigned'], empty: 'No new assignments.' },
  { title: 'In production', subtitle: 'On the bench', statuses: ['in_production'], empty: 'Nothing in production.' },
  { title: 'Rework', subtitle: 'Returned by QC', statuses: ['rework'], empty: 'No rework.' },
  { title: 'With QC', subtitle: 'Awaiting inspection', statuses: ['quality_control'], empty: 'Nothing with QC.' },
];

function BoardColumn({ title, subtitle, statuses, empty, technicianId, search }: (typeof COLUMNS)[number] & { technicianId?: string; search?: string }) {
  const q = useCases({ status: statuses, technicianId, search, sort: 'dueAt', dir: 'asc', perPage: 50 }, { refetchInterval: 60_000 });
  return (
    <section className="flex w-[min(340px,86vw)] shrink-0 flex-col gap-3 rounded-md bg-sunken/60 p-2.5" aria-labelledby={`col-${title}`}>
      <header className="flex items-baseline gap-2 px-1.5 pt-1">
        <h2 id={`col-${title}`} className="text-[12px] font-extrabold tracking-[.12em] text-ink uppercase">{title}</h2>
        <span className="rounded-full bg-card px-2 py-0.5 font-mono text-[11px] font-bold text-ink-2">{q.data?.meta.total ?? '·'}</span>
        <span className="ml-auto text-[11.5px] text-ink-3">{subtitle}</span>
      </header>
      {q.error ? (
        <ErrorState message={errorMessage(q.error)} onRetry={() => void q.refetch()} />
      ) : q.isLoading ? (
        [0, 1].map((i) => <Skeleton key={i} className="h-56 bg-card" />)
      ) : q.data?.data.length ? (
        q.data.data.map((c) => <CaseCard key={c.id} c={c} />)
      ) : (
        <p className="rounded-md border border-dashed border-line-strong bg-card px-3 py-6 text-center text-[13px] text-ink-3">{empty}</p>
      )}
    </section>
  );
}

export default function ProductionPage() {
  const { can, user } = useAuth();
  const scoped = !!user?.technicianId && !can(PERMISSIONS.CASES_VIEW_ALL);
  usePageTitle('Production', scoped ? 'Your bench, sorted by deadline' : 'Every case on the lab floor, sorted by deadline');
  const [technicianId, setTechnicianId] = useState<string | undefined>();
  const [search, setSearch] = useState('');
  const debounced = useDebouncedValue(search, 250);
  const techs = useTechnicians({ active: true, perPage: 100 }, !scoped && can([PERMISSIONS.TECHNICIANS_VIEW, PERMISSIONS.CASES_ASSIGN], 'any'));

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <SearchInput value={search} onChange={setSearch} placeholder="Case ID or patient…" />
        {techs.data && <FilterSelect label="Technician" value={technicianId} onChange={setTechnicianId} options={techs.data.data.map((t) => ({ value: t.id, label: `${t.name} (${t.activeCases})` }))} />}
      </div>
      <div className="-mx-3 overflow-x-auto px-3 pb-2 sm:-mx-5 sm:px-5 lg:-mx-7 lg:px-7" tabIndex={0} role="region" aria-label="Production board">
        <div className="flex items-start gap-3">
          {COLUMNS.filter((c) => !scoped || c.title !== 'Waiting for assignment').map((col) => (
            <BoardColumn key={col.title} {...col} technicianId={technicianId} search={debounced || undefined} />
          ))}
        </div>
      </div>
    </div>
  );
}
