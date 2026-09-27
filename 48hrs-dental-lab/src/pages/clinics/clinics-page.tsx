import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { Pencil, Plus } from 'lucide-react';
import { PERMISSIONS } from '@/lib/permissions';
import { useClinics } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { useUrlState } from '@/hooks/use-url-state';
import type { ClinicListItem } from '@/types/api';
import { formatMoney } from '@/utils/format';
import { ClinicFormDialog } from '@/components/directory/directory-forms';
import { DataTable } from '@/components/tables/data-table';
import { ClearFiltersButton, FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

const DEFAULTS = { search: '', status: undefined as string | undefined, sort: 'name', dir: 'asc', page: '1', perPage: '20' };

export default function ClinicsPage() {
  usePageTitle('Clinics', 'Clinics the lab works for');
  const { can } = useAuth();
  const navigate = useNavigate();
  const [f, setF, resetF] = useUrlState(DEFAULTS);
  const [editing, setEditing] = useState<ClinicListItem | null | 'new'>(null);
  const q = useClinics({ search: f.search || undefined, status: f.status as 'active' | 'inactive' | undefined, sort: f.sort, dir: f.dir as 'asc' | 'desc', page: Number(f.page), perPage: Number(f.perPage) });
  const manage = can(PERMISSIONS.CLINICS_MANAGE);
  const money = can([PERMISSIONS.INVOICES_VIEW, PERMISSIONS.PAYMENTS_VIEW], 'any');

  const columns = useMemo<ColumnDef<ClinicListItem, unknown>[]>(
    () => [
      { id: 'name', header: 'Clinic', meta: { sortKey: 'name' }, cell: ({ row }) => <div className="flex flex-col"><span className="font-semibold whitespace-nowrap">{row.original.name}</span><span className="text-xs text-ink-3">{row.original.address}</span></div> },
      { id: 'contact', header: 'Contact', meta: { cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => row.original.contactPerson || '—' },
      { id: 'phone', header: 'Phone', meta: { cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => row.original.phone },
      { id: 'email', header: 'Email', meta: { cellClassName: 'text-ink-2' }, cell: ({ row }) => row.original.email || '—' },
      { id: 'doctors', header: 'Doctors', meta: { align: 'right' }, cell: ({ row }) => <span className="font-mono">{row.original.doctorCount}</span> },
      { id: 'cases', header: 'Cases', meta: { sortKey: 'caseCount', align: 'right' }, cell: ({ row }) => <span className="font-mono font-bold">{row.original.caseCount}</span> },
      { id: 'active', header: 'Active', meta: { sortKey: 'activeCases', align: 'right' }, cell: ({ row }) => <span className="font-mono">{row.original.activeCases}</span> },
      ...(money ? ([{ id: 'out', header: 'Outstanding', meta: { sortKey: 'outstanding', align: 'right' }, cell: ({ row }) => <span className={`font-mono font-bold ${row.original.outstanding ? 'text-warning' : ''}`}>{formatMoney(row.original.outstanding)}</span> }] as ColumnDef<ClinicListItem, unknown>[]) : []),
      { id: 'status', header: 'Status', meta: { sortKey: 'status' }, cell: ({ row }) => <Badge tone={row.original.status === 'active' ? 'success' : 'neutral'}>{row.original.status === 'active' ? 'Active' : 'Inactive'}</Badge> },
      ...(manage ? ([{ id: 'actions', header: () => <span className="sr-only">Actions</span>, meta: { align: 'right' }, cell: ({ row }) => <Button variant="ghost" size="icon-sm" aria-label={`Edit ${row.original.name}`} onClick={() => setEditing(row.original)}><Pencil /></Button> }] as ColumnDef<ClinicListItem, unknown>[]) : []),
    ],
    [manage, money],
  );

  return (
    <div className="flex flex-col gap-4">
      {manage && <div className="flex justify-end"><Button onClick={() => setEditing('new')}><Plus /> Add clinic</Button></div>}
      <DataTable
        caption="Clinics"
        columns={columns}
        data={q.data?.data}
        meta={q.data?.meta}
        getRowId={(k) => k.id}
        isLoading={q.isLoading}
        isFetching={q.isFetching}
        error={q.error}
        onRetry={() => void q.refetch()}
        sort={{ key: f.sort, dir: f.dir as 'asc' | 'desc' }}
        onSortChange={(s) => setF({ sort: s.key ?? DEFAULTS.sort, dir: s.dir ?? DEFAULTS.dir })}
        onPageChange={(p) => setF({ page: String(p) }, { resetPage: false })}
        onPerPageChange={(n) => setF({ perPage: String(n) })}
        onRowClick={(k) => navigate(`/clinics/${k.id}`)}
        emptyTitle="No clinics found."
        renderMobileCard={(k) => (
          <div className="flex justify-between gap-2">
            <div className="flex flex-col"><span className="font-bold">{k.name}</span><span className="text-xs text-ink-2">{k.contactPerson} · {k.phone}</span></div>
            <span className="font-mono text-sm font-bold">{k.caseCount}</span>
          </div>
        )}
        toolbar={
          <ToolbarRow>
            <SearchInput value={f.search} onChange={(v) => setF({ search: v })} placeholder="Clinic, contact, phone…" />
            <FilterSelect label="Status" value={f.status} onChange={(v) => setF({ status: v })} options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} />
            <ClearFiltersButton show={!!(f.search || f.status)} onClear={resetF} />
          </ToolbarRow>
        }
      />
      <ClinicFormDialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)} record={editing && editing !== 'new' ? editing : null} />
    </div>
  );
}
