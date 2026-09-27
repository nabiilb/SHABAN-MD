import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';
import { PERMISSIONS } from '@/lib/permissions';
import { useClinics, useDeleteClinic } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { errorMessage } from '@/services/api/errors';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { usePageTitle } from '@/hooks/use-page-title';
import { useListState } from '@/hooks/use-list-state';
import type { ClinicListItem } from '@/types/api';
import { formatMoney } from '@/utils/format';
import { ClinicFormDialog } from '@/components/directory/directory-forms';
import { DataTable } from '@/components/tables/data-table';
import { RowActions } from '@/components/tables/row-actions';
import { ClearFiltersButton, FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Button } from '@/components/ui/button';
import { PageToolbar } from '@/components/ui/page-toolbar';
import { ActiveBadge } from '@/components/ui/record-badges';

const DEFAULTS = { search: '', status: undefined as string | undefined, sort: 'name', dir: 'asc', page: '1', perPage: '20' };

export default function ClinicsPage() {
  usePageTitle('Clinics', 'Clinics the lab works for');
  const { can } = useAuth();
  const navigate = useNavigate();
  const list = useListState(DEFAULTS);
  const { state: f, set: setF, reset: resetF } = list;
  const [editing, setEditing] = useState<ClinicListItem | null | 'new'>(null);
  const q = useClinics({ ...list.listParams, status: f.status as 'active' | 'inactive' | undefined });
  const manage = can(PERMISSIONS.CLINICS_MANAGE);
  const [toDelete, setToDelete] = useState<ClinicListItem | null>(null);
  const del = useDeleteClinic();
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
      { id: 'status', header: 'Status', meta: { sortKey: 'status' }, cell: ({ row }) => <ActiveBadge active={row.original.status === 'active'} /> },
      {
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        meta: { align: 'right' },
        cell: ({ row }) => (
          <RowActions
            label={row.original.name}
            actions={[
              { label: 'View details', icon: <Eye />, onSelect: () => navigate(`/clinics/${row.original.id}`) },
              { label: 'Edit', icon: <Pencil />, onSelect: () => setEditing(row.original), hidden: !manage },
              { label: 'Delete', icon: <Trash2 />, tone: 'danger', onSelect: () => setToDelete(row.original), hidden: !manage },
            ]}
          />
        ),
      },
    ],
    [manage, navigate, money],
  );

  return (
    <div className="flex flex-col gap-4">
      <PageToolbar actions={manage && <Button onClick={() => setEditing('new')}><Plus /> Add clinic</Button>} />
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
        {...list.tableProps}
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
      <ConfirmDialog
        open={!!toDelete}
        onOpenChange={(o) => !o && setToDelete(null)}
        title="Delete clinic?"
        tone="danger"
        confirmLabel="Delete clinic"
        loading={del.isPending}
        description={<>Delete <b>{toDelete?.name}</b>? A clinic with cases cannot be deleted — set it to inactive instead.</>}
        onConfirm={async () => {
          if (!toDelete) return;
          try {
            await del.mutateAsync(toDelete.id);
            toast.success('Clinic deleted');
          } catch (err) {
            toast.error(errorMessage(err));
          }
          setToDelete(null);
        }}
      />
    </div>
  );
}
