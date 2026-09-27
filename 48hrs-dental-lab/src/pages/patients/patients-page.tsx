import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus } from 'lucide-react';
import { PERMISSIONS } from '@/lib/permissions';
import { usePatients } from '@/hooks/api/use-directory';
import { useClinics } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { useUrlState } from '@/hooks/use-url-state';
import type { PatientListItem } from '@/types/api';
import { formatDate } from '@/utils/format';
import { PatientFormDialog } from '@/components/directory/directory-forms';
import { DataTable } from '@/components/tables/data-table';
import { ClearFiltersButton, FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Button } from '@/components/ui/button';

const DEFAULTS = { search: '', clinicId: undefined as string | undefined, sort: 'createdAt', dir: 'desc', page: '1', perPage: '20' };

export default function PatientsPage() {
  usePageTitle('Patients', 'Patient records and their case history');
  const { can } = useAuth();
  const navigate = useNavigate();
  const [f, setF, resetF] = useUrlState(DEFAULTS);
  const [adding, setAdding] = useState(false);
  const clinics = useClinics({ perPage: 200 }, can(PERMISSIONS.CLINICS_VIEW));
  const q = usePatients({ search: f.search || undefined, clinicId: f.clinicId, sort: f.sort, dir: f.dir as 'asc' | 'desc', page: Number(f.page), perPage: Number(f.perPage) });

  const columns = useMemo<ColumnDef<PatientListItem, unknown>[]>(
    () => [
      { id: 'code', header: 'Patient ID', meta: { sortKey: 'code' }, cell: ({ row }) => <span className="font-mono text-[13px] font-bold text-brand">{row.original.code}</span> },
      { id: 'name', header: 'Name', meta: { sortKey: 'name' }, cell: ({ row }) => <span className="font-semibold">{row.original.name}</span> },
      { id: 'phone', header: 'Phone', meta: { cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => row.original.phone || '—' },
      { id: 'email', header: 'Email', meta: { label: 'Email', cellClassName: 'text-ink-2' }, cell: ({ row }) => row.original.email || '—' },
      { id: 'clinic', header: 'Clinic', meta: { sortKey: 'clinic', cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => row.original.clinicName ?? '—' },
      { id: 'cases', header: 'Cases', meta: { sortKey: 'caseCount', align: 'right' }, cell: ({ row }) => <span className="font-mono font-bold">{row.original.caseCount}</span> },
      { id: 'last', header: 'Last case', meta: { sortKey: 'lastCaseAt', cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => formatDate(row.original.lastCaseAt) },
    ],
    [],
  );

  return (
    <div className="flex flex-col gap-4">
      {can(PERMISSIONS.PATIENTS_CREATE) && (
        <div className="flex justify-end"><Button onClick={() => setAdding(true)}><Plus /> Add patient</Button></div>
      )}
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
        sort={{ key: f.sort, dir: f.dir as 'asc' | 'desc' }}
        onSortChange={(s) => setF({ sort: s.key ?? DEFAULTS.sort, dir: s.dir ?? DEFAULTS.dir })}
        onPageChange={(p) => setF({ page: String(p) }, { resetPage: false })}
        onPerPageChange={(n) => setF({ perPage: String(n) })}
        onRowClick={(p) => navigate(`/patients/${p.id}`)}
        emptyTitle="No patients found."
        emptyDescription={f.search ? 'Try a different name, reference or phone number.' : undefined}
        renderMobileCard={(p) => (
          <div className="flex justify-between gap-2">
            <div className="flex flex-col"><span className="font-bold">{p.name}</span><span className="font-mono text-xs text-ink-3">{p.code} · {p.phone || 'no phone'}</span></div>
            <span className="font-mono text-sm font-bold">{p.caseCount} cases</span>
          </div>
        )}
        toolbar={
          <ToolbarRow>
            <SearchInput value={f.search} onChange={(v) => setF({ search: v })} placeholder="Name, patient ID or phone…" />
            {clinics.data && <FilterSelect label="Clinic" value={f.clinicId} onChange={(v) => setF({ clinicId: v })} options={clinics.data.data.map((k) => ({ value: k.id, label: k.name }))} />}
            <ClearFiltersButton show={!!(f.search || f.clinicId)} onClear={resetF} />
          </ToolbarRow>
        }
      />
      <PatientFormDialog open={adding} onOpenChange={setAdding} onSaved={(p) => navigate(`/patients/${p.id}`)} />
    </div>
  );
}
