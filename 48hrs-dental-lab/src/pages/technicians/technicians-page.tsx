import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { Eye, Pencil, Plus } from 'lucide-react';
import { PERMISSIONS } from '@/lib/permissions';
import { useTechnicians } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { useListState } from '@/hooks/use-list-state';
import type { TechnicianListItem } from '@/types/api';
import { formatPercent } from '@/utils/format';
import { TechnicianFormDialog } from '@/components/directory/directory-forms';
import { DataTable } from '@/components/tables/data-table';
import { RowActions } from '@/components/tables/row-actions';
import { ClearFiltersButton, FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { PageToolbar } from '@/components/ui/page-toolbar';
import { ActiveBadge } from '@/components/ui/record-badges';
import { ProgressBar } from '@/components/ui/feedback';

const DEFAULTS = { search: '', active: undefined as string | undefined, sort: 'name', dir: 'asc', page: '1', perPage: '20' };

export default function TechniciansPage() {
  usePageTitle('Technicians', 'Workload, output and on-time performance');
  const { can } = useAuth();
  const navigate = useNavigate();
  const list = useListState(DEFAULTS);
  const { state: f, set: setF, reset: resetF } = list;
  const [editing, setEditing] = useState<TechnicianListItem | null | 'new'>(null);
  const q = useTechnicians({ ...list.listParams, active: f.active === undefined ? undefined : f.active === 'true' });
  const manage = can(PERMISSIONS.TECHNICIANS_MANAGE);
  const maxLoad = Math.max(1, ...(q.data?.data ?? []).map((t) => t.activeCases));

  const columns = useMemo<ColumnDef<TechnicianListItem, unknown>[]>(
    () => [
      { id: 'name', header: 'Technician', meta: { sortKey: 'name' }, cell: ({ row }) => <div className="flex items-center gap-3"><Avatar name={row.original.name} size="sm" /><div className="flex flex-col"><span className="font-semibold whitespace-nowrap">{row.original.name}</span><span className="text-xs text-ink-3">{row.original.specialty}</span></div></div> },
      { id: 'contact', header: 'Contact', cell: ({ row }) => <div className="flex flex-col text-[13px] text-ink-2"><span>{row.original.email}</span><span className="whitespace-nowrap">{row.original.phone}</span></div> },
      { id: 'load', header: 'Current workload', meta: { sortKey: 'activeCases' }, cell: ({ row }) => <div className="flex min-w-[140px] flex-col gap-1"><span className="font-mono text-[13px] font-bold">{row.original.activeCases} active</span><ProgressBar value={row.original.activeCases / maxLoad} tone={row.original.overdue ? 'danger' : 'info'} label="Workload" /></div> },
      { id: 'due', header: 'Due today', meta: { align: 'right' }, cell: ({ row }) => <span className="font-mono">{row.original.dueToday}</span> },
      { id: 'overdue', header: 'Overdue', meta: { sortKey: 'overdue', align: 'right' }, cell: ({ row }) => <span className={`font-mono font-bold ${row.original.overdue ? 'text-danger' : ''}`}>{row.original.overdue}</span> },
      { id: 'done', header: 'Completed', meta: { sortKey: 'completedCases', align: 'right' }, cell: ({ row }) => <span className="font-mono">{row.original.completedCases}</span> },
      { id: 'ontime', header: 'On time', meta: { sortKey: 'onTimeRate', align: 'right' }, cell: ({ row }) => <span className="font-mono font-bold">{formatPercent(row.original.onTimeRate)}</span> },
      { id: 'status', header: 'Status', cell: ({ row }) => <ActiveBadge active={row.original.active} /> },
      {
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        meta: { align: 'right' },
        cell: ({ row }) => (
          <RowActions
            label={row.original.name}
            actions={[
              { label: 'View details', icon: <Eye />, onSelect: () => navigate(`/technicians/${row.original.id}`) },
              { label: 'Edit', icon: <Pencil />, onSelect: () => setEditing(row.original), hidden: !manage },
            ]}
          />
        ),
      },
    ],
    [manage, navigate, maxLoad],
  );

  return (
    <div className="flex flex-col gap-4">
      <PageToolbar actions={manage && <Button onClick={() => setEditing('new')}><Plus /> Add technician</Button>} />
      <DataTable
        caption="Technicians"
        columns={columns}
        data={q.data?.data}
        meta={q.data?.meta}
        getRowId={(t) => t.id}
        isLoading={q.isLoading}
        isFetching={q.isFetching}
        error={q.error}
        onRetry={() => void q.refetch()}
        {...list.tableProps}
        onRowClick={(t) => navigate(`/technicians/${t.id}`)}
        emptyTitle="No technicians found."
        renderMobileCard={(t) => (
          <div className="flex items-center justify-between gap-2">
            <div className="flex items-center gap-3"><Avatar name={t.name} size="sm" /><div className="flex flex-col"><span className="font-bold">{t.name}</span><span className="text-xs text-ink-2">{t.specialty}</span></div></div>
            <div className="flex flex-col items-end text-xs"><span className="font-mono font-bold">{t.activeCases} active</span>{t.overdue > 0 && <span className="text-danger">{t.overdue} overdue</span>}</div>
          </div>
        )}
        toolbar={
          <ToolbarRow>
            <SearchInput value={f.search} onChange={(v) => setF({ search: v })} placeholder="Name, specialty, email…" />
            <FilterSelect label="Status" value={f.active} onChange={(v) => setF({ active: v })} options={[{ value: 'true', label: 'Active' }, { value: 'false', label: 'Inactive' }]} />
            <ClearFiltersButton show={!!(f.search || f.active)} onClear={resetF} />
          </ToolbarRow>
        }
      />
      <TechnicianFormDialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)} record={editing && editing !== 'new' ? editing : null} />
    </div>
  );
}
