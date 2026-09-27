import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { useActivity } from '@/hooks/api/use-admin';
import { usePageTitle } from '@/hooks/use-page-title';
import { useUrlState } from '@/hooks/use-url-state';
import type { ActivityLogEntry } from '@/types/models';
import { formatDateTime } from '@/utils/format';
import { DataTable } from '@/components/tables/data-table';
import { ClearFiltersButton, FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Badge } from '@/components/ui/badge';

const DEFAULTS = { search: '', subjectType: undefined as string | undefined, page: '1', perPage: '50' };
const TYPES = ['case', 'invoice', 'user', 'role', 'service', 'settings', 'patient', 'doctor', 'clinic', 'technician', 'auth'];

export default function ActivityPage() {
  usePageTitle('Activity Log', 'Every change, who made it and when');
  const navigate = useNavigate();
  const [f, setF, resetF] = useUrlState(DEFAULTS);
  const q = useActivity({ search: f.search || undefined, subjectType: f.subjectType, page: Number(f.page), perPage: Number(f.perPage) });

  const columns = useMemo<ColumnDef<ActivityLogEntry, unknown>[]>(
    () => [
      { id: 'time', header: 'Time', meta: { cellClassName: 'whitespace-nowrap font-mono text-xs text-ink-2' }, cell: ({ row }) => formatDateTime(row.original.createdAt) },
      { id: 'subject', header: 'Record', cell: ({ row }) => <div className="flex flex-col items-start gap-1"><Badge>{row.original.subjectType}</Badge>{row.original.subjectLabel && <span className="font-mono text-xs font-bold text-brand">{row.original.subjectLabel}</span>}</div> },
      { id: 'desc', header: 'What happened', meta: { cellClassName: 'text-[13.5px]' }, cell: ({ row }) => row.original.description },
      { id: 'user', header: 'By', meta: { cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => row.original.userName },
    ],
    [],
  );

  return (
    <DataTable
      caption="Activity log"
      columns={columns}
      data={q.data?.data}
      meta={q.data?.meta}
      getRowId={(a) => a.id}
      isLoading={q.isLoading}
      isFetching={q.isFetching}
      error={q.error}
      onRetry={() => void q.refetch()}
      onPageChange={(p) => setF({ page: String(p) }, { resetPage: false })}
      onPerPageChange={(n) => setF({ perPage: String(n) })}
      onRowClick={(a) => {
        if (a.subjectType === 'case' && a.subjectId) navigate(`/cases/${a.subjectId}`);
        else if (a.subjectType === 'invoice' && a.subjectId) navigate(`/invoices/${a.subjectId}`);
      }}
      emptyTitle="No activity recorded."
      renderMobileCard={(a) => (
        <div className="flex flex-col gap-0.5">
          <span className="font-mono text-[11px] text-ink-3">{formatDateTime(a.createdAt)} · {a.userName}</span>
          {a.subjectLabel && <span className="font-mono text-xs font-bold text-brand">{a.subjectLabel}</span>}
          <span className="text-[13px]">{a.description}</span>
        </div>
      )}
      toolbar={
        <ToolbarRow>
          <SearchInput value={f.search} onChange={(v) => setF({ search: v })} placeholder="User, case, description…" />
          <FilterSelect label="Record" value={f.subjectType} onChange={(v) => setF({ subjectType: v })} options={TYPES.map((t) => ({ value: t, label: t[0].toUpperCase() + t.slice(1) }))} />
          <ClearFiltersButton show={!!(f.search || f.subjectType)} onClear={resetF} />
        </ToolbarRow>
      }
    />
  );
}
