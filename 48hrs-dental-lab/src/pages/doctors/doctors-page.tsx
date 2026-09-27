import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { Pencil, Plus } from 'lucide-react';
import { PERMISSIONS } from '@/lib/permissions';
import { useClinics, useDoctors } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { useUrlState } from '@/hooks/use-url-state';
import type { DoctorListItem } from '@/types/api';
import { DoctorFormDialog } from '@/components/directory/directory-forms';
import { DataTable } from '@/components/tables/data-table';
import { ClearFiltersButton, FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

const DEFAULTS = { search: '', clinicId: undefined as string | undefined, status: undefined as string | undefined, sort: 'name', dir: 'asc', page: '1', perPage: '20' };

export default function DoctorsPage() {
  usePageTitle('Doctors', 'Dentists who send cases to the lab');
  const { can } = useAuth();
  const navigate = useNavigate();
  const [f, setF, resetF] = useUrlState(DEFAULTS);
  const [editing, setEditing] = useState<DoctorListItem | null | 'new'>(null);
  const clinics = useClinics({ perPage: 200 }, can(PERMISSIONS.CLINICS_VIEW));
  const q = useDoctors({ search: f.search || undefined, clinicId: f.clinicId, status: f.status as 'active' | 'inactive' | undefined, sort: f.sort, dir: f.dir as 'asc' | 'desc', page: Number(f.page), perPage: Number(f.perPage) });
  const manage = can(PERMISSIONS.DOCTORS_MANAGE);

  const columns = useMemo<ColumnDef<DoctorListItem, unknown>[]>(
    () => [
      { id: 'name', header: 'Name', meta: { sortKey: 'name' }, cell: ({ row }) => <span className="font-semibold whitespace-nowrap">{row.original.name}</span> },
      { id: 'clinic', header: 'Clinic', meta: { sortKey: 'clinic', cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => row.original.clinicName },
      { id: 'phone', header: 'Phone', meta: { cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => row.original.phone },
      { id: 'email', header: 'Email', meta: { cellClassName: 'text-ink-2' }, cell: ({ row }) => row.original.email || '—' },
      { id: 'specialty', header: 'Specialty', cell: ({ row }) => row.original.specialty || '—' },
      { id: 'cases', header: 'Cases', meta: { sortKey: 'caseCount', align: 'right' }, cell: ({ row }) => <span className="font-mono font-bold">{row.original.caseCount}</span> },
      { id: 'active', header: 'Active', meta: { sortKey: 'activeCases', align: 'right' }, cell: ({ row }) => <span className="font-mono">{row.original.activeCases}</span> },
      { id: 'status', header: 'Status', meta: { sortKey: 'status' }, cell: ({ row }) => <Badge tone={row.original.status === 'active' ? 'success' : 'neutral'}>{row.original.status === 'active' ? 'Active' : 'Inactive'}</Badge> },
      ...(manage
        ? ([{ id: 'actions', header: () => <span className="sr-only">Actions</span>, meta: { align: 'right' }, cell: ({ row }) => <Button variant="ghost" size="icon-sm" aria-label={`Edit ${row.original.name}`} onClick={() => setEditing(row.original)}><Pencil /></Button> }] as ColumnDef<DoctorListItem, unknown>[])
        : []),
    ],
    [manage],
  );

  return (
    <div className="flex flex-col gap-4">
      {manage && <div className="flex justify-end"><Button onClick={() => setEditing('new')}><Plus /> Add doctor</Button></div>}
      <DataTable
        caption="Doctors"
        columns={columns}
        data={q.data?.data}
        meta={q.data?.meta}
        getRowId={(d) => d.id}
        isLoading={q.isLoading}
        isFetching={q.isFetching}
        error={q.error}
        onRetry={() => void q.refetch()}
        sort={{ key: f.sort, dir: f.dir as 'asc' | 'desc' }}
        onSortChange={(s) => setF({ sort: s.key ?? DEFAULTS.sort, dir: s.dir ?? DEFAULTS.dir })}
        onPageChange={(p) => setF({ page: String(p) }, { resetPage: false })}
        onPerPageChange={(n) => setF({ perPage: String(n) })}
        onRowClick={(d) => navigate(`/doctors/${d.id}`)}
        emptyTitle="No doctors found."
        renderMobileCard={(d) => (
          <div className="flex justify-between gap-2">
            <div className="flex flex-col"><span className="font-bold">{d.name}</span><span className="text-xs text-ink-2">{d.clinicName} · {d.phone}</span></div>
            <span className="font-mono text-sm font-bold">{d.caseCount}</span>
          </div>
        )}
        toolbar={
          <ToolbarRow>
            <SearchInput value={f.search} onChange={(v) => setF({ search: v })} placeholder="Name, phone or email…" />
            {clinics.data && <FilterSelect label="Clinic" value={f.clinicId} onChange={(v) => setF({ clinicId: v })} options={clinics.data.data.map((k) => ({ value: k.id, label: k.name }))} />}
            <FilterSelect label="Status" value={f.status} onChange={(v) => setF({ status: v })} options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} />
            <ClearFiltersButton show={!!(f.search || f.clinicId || f.status)} onClear={resetF} />
          </ToolbarRow>
        }
      />
      <DoctorFormDialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)} record={editing && editing !== 'new' ? editing : null} />
    </div>
  );
}
