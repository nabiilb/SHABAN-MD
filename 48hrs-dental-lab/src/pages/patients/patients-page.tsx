import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { Eye, FilePlus2, Pencil, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { PERMISSIONS } from '@/lib/permissions';
import { useClinics, useDeletePatient, usePatients } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { useListState } from '@/hooks/use-list-state';
import { usePageTitle } from '@/hooks/use-page-title';
import { errorMessage } from '@/services/api/errors';
import type { PatientListItem } from '@/types/api';
import { formatDate } from '@/utils/format';
import { PatientFormDialog } from '@/components/directory/directory-forms';
import { DataTable } from '@/components/tables/data-table';
import { RowActions } from '@/components/tables/row-actions';
import { ClearFiltersButton, FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { PageToolbar } from '@/components/ui/page-toolbar';

const DEFAULTS = { search: '', clinicId: undefined as string | undefined, sort: 'createdAt', dir: 'desc', page: '1', perPage: '20' };

export default function PatientsPage() {
  usePageTitle('Patients', 'Patient records and their case history');
  const { can } = useAuth();
  const navigate = useNavigate();
  const list = useListState(DEFAULTS);
  const f = list.state;
  const [editing, setEditing] = useState<PatientListItem | null | 'new'>(null);
  const [toDelete, setToDelete] = useState<PatientListItem | null>(null);
  const del = useDeletePatient();
  const clinics = useClinics({ perPage: 200 }, can(PERMISSIONS.CLINICS_VIEW));
  const q = usePatients({ ...list.listParams, clinicId: f.clinicId });

  const columns = useMemo<ColumnDef<PatientListItem, unknown>[]>(
    () => [
      { id: 'code', header: 'Patient ID', meta: { sortKey: 'code' }, cell: ({ row }) => <span className="font-mono text-[13px] font-bold text-brand">{row.original.code}</span> },
      { id: 'name', header: 'Name', meta: { sortKey: 'name' }, cell: ({ row }) => <span className="font-semibold">{row.original.name}</span> },
      { id: 'phone', header: 'Phone', meta: { cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => row.original.phone || '—' },
      { id: 'email', header: 'Email', meta: { label: 'Email', cellClassName: 'text-ink-2' }, cell: ({ row }) => row.original.email || '—' },
      { id: 'clinic', header: 'Clinic', meta: { sortKey: 'clinic', cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => row.original.clinicName ?? '—' },
      { id: 'cases', header: 'Cases', meta: { sortKey: 'caseCount', align: 'right' }, cell: ({ row }) => <span className="font-mono font-bold">{row.original.caseCount}</span> },
      { id: 'last', header: 'Last case', meta: { sortKey: 'lastCaseAt', cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => formatDate(row.original.lastCaseAt) },
      {
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        meta: { align: 'right' },
        cell: ({ row }) => (
          <RowActions
            label={row.original.name}
            actions={[
              { label: 'View patient', icon: <Eye />, onSelect: () => navigate(`/patients/${row.original.id}`) },
              { label: 'New case', icon: <FilePlus2 />, onSelect: () => navigate('/cases/new'), hidden: !can([PERMISSIONS.CASES_CREATE, PERMISSIONS.CASES_SUBMIT], 'any') },
              { label: 'Edit', icon: <Pencil />, onSelect: () => setEditing(row.original), hidden: !can(PERMISSIONS.PATIENTS_EDIT) },
              { label: 'Delete', icon: <Trash2 />, tone: 'danger', onSelect: () => setToDelete(row.original), hidden: !can(PERMISSIONS.PATIENTS_DELETE) },
            ]}
          />
        ),
      },
    ],
    [can, navigate],
  );

  return (
    <div className="flex flex-col gap-4">
      <PageToolbar actions={can(PERMISSIONS.PATIENTS_CREATE) && <Button onClick={() => setEditing('new')}><Plus /> Add patient</Button>} />
      <DataTable
        caption="Patients"
        columns={columns}
        data={q.data?.data}
        meta={q.data?.meta}
        getRowId={(p) => p.id}
        isLoading={q.isLoading}
        isFetching={q.isFetching}
        error={q.error}
        onRetry={() => void q.refetch()}
        {...list.tableProps}
        onRowClick={(p) => navigate(`/patients/${p.id}`)}
        emptyTitle="No patients found."
        emptyDescription={list.filtersActive ? 'Try a different name, reference or phone number.' : 'Patients are added here or while registering a case.'}
        renderMobileCard={(p) => (
          <div className="flex justify-between gap-2">
            <div className="flex flex-col"><span className="font-bold">{p.name}</span><span className="font-mono text-xs text-ink-3">{p.code} · {p.phone || 'no phone'}</span></div>
            <span className="font-mono text-sm font-bold">{p.caseCount} cases</span>
          </div>
        )}
        toolbar={
          <ToolbarRow>
            <SearchInput value={f.search} onChange={(v) => list.set({ search: v })} placeholder="Name, patient ID or phone…" />
            {clinics.data && <FilterSelect label="Clinic" value={f.clinicId} onChange={(v) => list.set({ clinicId: v })} options={clinics.data.data.map((k) => ({ value: k.id, label: k.name }))} />}
            <ClearFiltersButton show={list.filtersActive} onClear={list.reset} />
          </ToolbarRow>
        }
      />
      <PatientFormDialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)} record={editing && editing !== 'new' ? editing : null} onSaved={(p) => editing === 'new' && navigate(`/patients/${p.id}`)} />
      <ConfirmDialog
        open={!!toDelete}
        onOpenChange={(o) => !o && setToDelete(null)}
        title="Delete patient?"
        tone="danger"
        confirmLabel="Delete patient"
        loading={del.isPending}
        description={<>Delete <b>{toDelete?.name}</b>? Patients with cases cannot be deleted — their records are part of the case history.</>}
        onConfirm={async () => {
          if (!toDelete) return;
          try {
            await del.mutateAsync(toDelete.id);
            toast.success('Patient deleted');
          } catch (err) {
            toast.error(errorMessage(err));
          }
          setToDelete(null);
        }}
      />
    </div>
  );
}
