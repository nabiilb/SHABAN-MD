import { useMemo } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { Download } from 'lucide-react';
import { PAYMENT_METHOD_LABELS } from '@/lib/constants';
import { PERMISSIONS } from '@/lib/permissions';
import { useClinics } from '@/hooks/api/use-directory';
import { usePayments } from '@/hooks/api/use-finance';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { useUrlState } from '@/hooks/use-url-state';
import type { PaymentListItem, PaymentMethod } from '@/types/models';
import { downloadCsv } from '@/utils/download';
import { formatDateTime, formatMoney } from '@/utils/format';
import { DataTable } from '@/components/tables/data-table';
import { ClearFiltersButton, DateFilter, FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Button } from '@/components/ui/button';

const DEFAULTS = { search: '', method: undefined as string | undefined, clinicId: undefined as string | undefined, from: undefined as string | undefined, to: undefined as string | undefined, sort: 'paidAt', dir: 'desc', page: '1', perPage: '20' };

export default function PaymentsPage() {
  usePageTitle('Payments', 'Every payment received');
  const { can } = useAuth();
  const navigate = useNavigate();
  const [f, setF, resetF] = useUrlState(DEFAULTS);
  const clinics = useClinics({ perPage: 200 }, can(PERMISSIONS.CLINICS_VIEW));
  const q = usePayments({ search: f.search || undefined, method: f.method as PaymentMethod | undefined, clinicId: f.clinicId, from: f.from, to: f.to, sort: f.sort, dir: f.dir as 'asc' | 'desc', page: Number(f.page), perPage: Number(f.perPage) });
  const pageTotal = (q.data?.data ?? []).reduce((s, p) => s + p.amount, 0);

  const columns = useMemo<ColumnDef<PaymentListItem, unknown>[]>(
    () => [
      { id: 'date', header: 'Date', meta: { sortKey: 'paidAt', cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => formatDateTime(row.original.paidAt) },
      { id: 'amount', header: 'Amount', meta: { sortKey: 'amount', align: 'right' }, cell: ({ row }) => <span className="font-mono font-bold">{formatMoney(row.original.amount)}</span> },
      { id: 'method', header: 'Method', meta: { sortKey: 'method' }, cell: ({ row }) => PAYMENT_METHOD_LABELS[row.original.method] },
      { id: 'ref', header: 'Reference', meta: { cellClassName: 'font-mono text-[12.5px]' }, cell: ({ row }) => row.original.reference || '—' },
      { id: 'inv', header: 'Invoice', meta: { sortKey: 'invoiceNumber' }, cell: ({ row }) => <span className="font-mono text-[13px] font-bold text-brand">{row.original.invoiceNumber}</span> },
      { id: 'case', header: 'Case', cell: ({ row }) => <Link to={`/cases/${row.original.caseId}`} onClick={(e) => e.stopPropagation()} className="font-mono text-[12.5px] hover:underline">{row.original.caseNumber}</Link> },
      { id: 'clinic', header: 'Clinic', meta: { sortKey: 'clinic', cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => row.original.clinic.name },
      { id: 'by', header: 'Received by', meta: { cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => row.original.receivedByName },
    ],
    [],
  );

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-[13px] text-ink-2">This page: <b className="font-mono text-ink">{formatMoney(pageTotal)}</b> across {q.data?.data.length ?? 0} payments</p>
        <Button variant="outline" disabled={!q.data?.data.length} onClick={() => q.data && downloadCsv(`payments-${new Date().toISOString().slice(0, 10)}.csv`, ['Date', 'Amount', 'Method', 'Reference', 'Invoice', 'Case', 'Clinic', 'Received by'], q.data.data.map((p) => [formatDateTime(p.paidAt), p.amount, PAYMENT_METHOD_LABELS[p.method], p.reference, p.invoiceNumber, p.caseNumber, p.clinic.name, p.receivedByName]))}>
          <Download /> Export page
        </Button>
      </div>
      <DataTable
        caption="Payments"
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
        onRowClick={(p) => navigate(`/invoices/${p.invoiceId}`)}
        emptyTitle="No payments found."
        renderMobileCard={(p) => (
          <div className="flex justify-between gap-2">
            <div className="flex flex-col"><span className="font-mono text-[12.5px] font-bold text-brand">{p.invoiceNumber}</span><span className="text-xs text-ink-2">{p.clinic.name} · {PAYMENT_METHOD_LABELS[p.method]}</span><span className="text-xs text-ink-3">{formatDateTime(p.paidAt)}</span></div>
            <span className="font-mono font-bold">{formatMoney(p.amount)}</span>
          </div>
        )}
        toolbar={
          <ToolbarRow>
            <SearchInput value={f.search} onChange={(v) => setF({ search: v })} placeholder="Invoice, case, reference…" />
            <FilterSelect label="Method" value={f.method} onChange={(v) => setF({ method: v })} options={Object.entries(PAYMENT_METHOD_LABELS).map(([value, label]) => ({ value, label }))} />
            {clinics.data && <FilterSelect label="Clinic" value={f.clinicId} onChange={(v) => setF({ clinicId: v })} options={clinics.data.data.map((k) => ({ value: k.id, label: k.name }))} />}
            <DateFilter label="From" value={f.from} onChange={(v) => setF({ from: v })} />
            <DateFilter label="To" value={f.to} onChange={(v) => setF({ to: v })} />
            <ClearFiltersButton show={!!(f.search || f.method || f.clinicId || f.from || f.to)} onClear={resetF} />
          </ToolbarRow>
        }
      />
    </div>
  );
}
