import { useMemo } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { Download } from 'lucide-react';
import { PAYMENT_STATUS_META } from '@/lib/constants';
import { PERMISSIONS } from '@/lib/permissions';
import { useClinics } from '@/hooks/api/use-directory';
import { useInvoices } from '@/hooks/api/use-finance';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { useUrlState } from '@/hooks/use-url-state';
import type { InvoiceListItem, PaymentStatus } from '@/types/models';
import { downloadCsv } from '@/utils/download';
import { formatDate, formatMoney } from '@/utils/format';
import { PaymentBadge } from '@/components/cases/badges';
import { DataTable } from '@/components/tables/data-table';
import { ClearFiltersButton, DateFilter, FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Button } from '@/components/ui/button';

const DEFAULTS = { search: '', status: undefined as string | undefined, clinicId: undefined as string | undefined, from: undefined as string | undefined, to: undefined as string | undefined, sort: 'issuedAt', dir: 'desc', page: '1', perPage: '20' };

export default function InvoicesPage() {
  usePageTitle('Invoices', 'One invoice per accepted case');
  const { can, user } = useAuth();
  const navigate = useNavigate();
  const [f, setF, resetF] = useUrlState(DEFAULTS);
  const clinics = useClinics({ perPage: 200 }, can(PERMISSIONS.CLINICS_VIEW) && !user?.clinicId);
  const q = useInvoices({ search: f.search || undefined, status: f.status as PaymentStatus | undefined, clinicId: f.clinicId, from: f.from, to: f.to, sort: f.sort, dir: f.dir as 'asc' | 'desc', page: Number(f.page), perPage: Number(f.perPage) });

  const columns = useMemo<ColumnDef<InvoiceListItem, unknown>[]>(
    () => [
      { id: 'n', header: 'Invoice', meta: { sortKey: 'invoiceNumber' }, cell: ({ row }) => <span className="font-mono text-[13px] font-bold text-brand">{row.original.invoiceNumber}</span> },
      { id: 'case', header: 'Case', cell: ({ row }) => <Link to={`/cases/${row.original.caseId}`} onClick={(e) => e.stopPropagation()} className="font-mono text-[12.5px] whitespace-nowrap hover:underline">{row.original.caseNumber}</Link> },
      { id: 'patient', header: 'Patient', meta: { cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => row.original.patient.name },
      { id: 'clinic', header: 'Doctor / clinic', meta: { sortKey: 'clinic' }, cell: ({ row }) => <div className="flex flex-col"><span className="whitespace-nowrap">{row.original.doctor.name}</span><span className="text-xs whitespace-nowrap text-ink-3">{row.original.clinic.name}</span></div> },
      { id: 'total', header: 'Total', meta: { sortKey: 'total', align: 'right' }, cell: ({ row }) => <span className="font-mono">{formatMoney(row.original.total)}</span> },
      { id: 'paid', header: 'Paid', meta: { sortKey: 'paid', align: 'right' }, cell: ({ row }) => <span className="font-mono text-success">{formatMoney(row.original.paid)}</span> },
      { id: 'rem', header: 'Remaining', meta: { sortKey: 'remaining', align: 'right' }, cell: ({ row }) => <span className={`font-mono font-bold ${row.original.remaining ? 'text-ink' : 'text-ink-3'}`}>{formatMoney(row.original.remaining)}</span> },
      { id: 'status', header: 'Status', meta: { sortKey: 'status' }, cell: ({ row }) => <PaymentBadge status={row.original.status} /> },
      { id: 'due', header: 'Due date', meta: { sortKey: 'dueDate', cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => formatDate(row.original.dueDate) },
    ],
    [],
  );

  const exportCsv = (rows: InvoiceListItem[]) =>
    downloadCsv(`invoices-${new Date().toISOString().slice(0, 10)}.csv`, ['Invoice', 'Case', 'Patient', 'Doctor', 'Clinic', 'Issued', 'Due', 'Total', 'Paid', 'Remaining', 'Status'], rows.map((i) => [i.invoiceNumber, i.caseNumber, i.patient.name, i.doctor.name, i.clinic.name, formatDate(i.issuedAt), formatDate(i.dueDate), i.total, i.paid, i.remaining, PAYMENT_STATUS_META[i.status].label]));

  return (
    <div className="flex flex-col gap-4">
      <div className="flex justify-end"><Button variant="outline" onClick={() => q.data && exportCsv(q.data.data)} disabled={!q.data?.data.length}><Download /> Export page</Button></div>
      <DataTable
        caption="Invoices"
        columns={columns}
        data={q.data?.data}
        meta={q.data?.meta}
        getRowId={(i) => i.id}
        isLoading={q.isLoading}
        isFetching={q.isFetching}
        error={q.error}
        onRetry={() => void q.refetch()}
        sort={{ key: f.sort, dir: f.dir as 'asc' | 'desc' }}
        onSortChange={(s) => setF({ sort: s.key ?? DEFAULTS.sort, dir: s.dir ?? DEFAULTS.dir })}
        onPageChange={(p) => setF({ page: String(p) }, { resetPage: false })}
        onPerPageChange={(n) => setF({ perPage: String(n) })}
        onRowClick={(i) => navigate(`/invoices/${i.id}`)}
        bulkActions={(rows, clear) => <Button size="sm" variant="secondary" onClick={() => { exportCsv(rows); clear(); }}><Download /> Export {rows.length} selected</Button>}
        emptyTitle="No invoices found."
        renderMobileCard={(i) => (
          <div className="flex items-start justify-between gap-2">
            <div className="flex flex-col"><span className="font-mono text-[12.5px] font-bold text-brand">{i.invoiceNumber}</span><span className="font-semibold">{i.patient.name}</span><span className="text-xs text-ink-2">{i.clinic.name}</span></div>
            <div className="flex flex-col items-end gap-1"><PaymentBadge status={i.status} /><span className="font-mono text-sm font-bold">{formatMoney(i.remaining)}</span></div>
          </div>
        )}
        toolbar={
          <ToolbarRow>
            <SearchInput value={f.search} onChange={(v) => setF({ search: v })} placeholder="Invoice, case, patient, clinic…" />
            <FilterSelect label="Status" value={f.status} onChange={(v) => setF({ status: v })} options={Object.entries(PAYMENT_STATUS_META).map(([value, m]) => ({ value, label: m.label }))} />
            {clinics.data && <FilterSelect label="Clinic" value={f.clinicId} onChange={(v) => setF({ clinicId: v })} options={clinics.data.data.map((k) => ({ value: k.id, label: k.name }))} />}
            <DateFilter label="From" value={f.from} onChange={(v) => setF({ from: v })} />
            <DateFilter label="To" value={f.to} onChange={(v) => setF({ to: v })} />
            <ClearFiltersButton show={!!(f.search || f.status || f.clinicId || f.from || f.to)} onClear={resetF} />
          </ToolbarRow>
        }
      />
    </div>
  );
}
