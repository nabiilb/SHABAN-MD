import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { QC_ISSUE_LABELS } from '@/lib/constants';
import { useCases } from '@/hooks/api/use-cases';
import { useQualityChecks } from '@/hooks/api/use-lab';
import { usePageTitle } from '@/hooks/use-page-title';
import { useUrlState } from '@/hooks/use-url-state';
import type { QualityCheckListItem } from '@/types/api';
import { formatDateTime } from '@/utils/format';
import { StatusBadge } from '@/components/cases/badges';
import { CaseQueueSection } from '@/components/dashboard/case-queue-section';
import { StatCard, StatGrid } from '@/components/dashboard/stat-card';
import { DataTable } from '@/components/tables/data-table';
import { SearchInput } from '@/components/tables/toolbar';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

const DEFAULTS = { tab: 'pending', search: '', page: '1' };

function QcRecords({ result, search, page, onSearch, onPage }: { result: 'passed' | 'failed'; search: string; page: number; onSearch: (s: string) => void; onPage: (p: number) => void }) {
  const navigate = useNavigate();
  const q = useQualityChecks({ result, search: search || undefined, page, perPage: 20 });
  const columns = useMemo<ColumnDef<QualityCheckListItem, unknown>[]>(
    () => [
      { id: 'case', header: 'Case', cell: ({ row }) => <span className="font-mono text-[13px] font-bold text-brand">{row.original.caseNumber}</span> },
      { id: 'patient', header: 'Patient', cell: ({ row }) => <div className="flex flex-col"><span className="font-semibold whitespace-nowrap">{row.original.patientName}</span><span className="text-xs text-ink-3">{row.original.clinicName}</span></div> },
      { id: 'tech', header: 'Technician', meta: { cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => row.original.technicianName ?? '—' },
      { id: 'by', header: 'Checked by', meta: { cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => row.original.checkedByName },
      { id: 'at', header: 'Date / time', meta: { cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => formatDateTime(row.original.checkedAt) },
      { id: 'result', header: 'Result', cell: ({ row }) => <Badge tone={row.original.result === 'passed' ? 'success' : 'danger'}>{row.original.result === 'passed' ? 'Passed' : 'Failed'}</Badge> },
      { id: 'issues', header: 'Issues', cell: ({ row }) => (row.original.issues.length ? <div className="flex flex-wrap gap-1">{row.original.issues.map((i) => <Badge key={i} tone="warning">{QC_ISSUE_LABELS[i]}</Badge>)}</div> : <span className="text-ink-3">—</span>) },
      { id: 'notes', header: 'Notes', meta: { cellClassName: 'max-w-[280px] text-[13px] text-ink-2' }, cell: ({ row }) => row.original.notes || '—' },
      { id: 'now', header: 'Case now', cell: ({ row }) => <StatusBadge status={row.original.caseStatus} /> },
    ],
    [],
  );
  return (
    <DataTable
      caption={`QC ${result}`}
      columns={columns}
      data={q.data?.data}
      meta={q.data?.meta}
      getRowId={(r) => r.id}
      isLoading={q.isLoading}
      isFetching={q.isFetching}
      error={q.error}
      onRetry={() => void q.refetch()}
      onPageChange={onPage}
      onRowClick={(r) => navigate(`/cases/${r.caseId}`)}
      emptyTitle={result === 'passed' ? 'No passed inspections yet.' : 'No failed inspections.'}
      toolbar={<SearchInput value={search} onChange={onSearch} placeholder="Case, patient, inspector…" />}
      renderMobileCard={(r) => (
        <div className="flex flex-col gap-1">
          <div className="flex justify-between"><span className="font-mono text-[12.5px] font-bold text-brand">{r.caseNumber}</span><Badge tone={r.result === 'passed' ? 'success' : 'danger'}>{r.result}</Badge></div>
          <span className="font-semibold">{r.patientName}</span>
          <span className="text-xs text-ink-3">{r.checkedByName} · {formatDateTime(r.checkedAt)}</span>
          {r.notes && <span className="text-xs text-ink-2">{r.notes}</span>}
        </div>
      )}
    />
  );
}

export default function QualityControlPage() {
  usePageTitle('Quality Control', 'Inspect finished work — pass it, or send it back for rework');
  const [f, setF] = useUrlState(DEFAULTS);
  const pending = useCases({ status: ['quality_control'], perPage: 1 });
  const rework = useCases({ status: ['rework'], perPage: 1 });
  const passed = useQualityChecks({ result: 'passed', perPage: 1 });
  const failed = useQualityChecks({ result: 'failed', perPage: 1 });

  return (
    <div className="flex flex-col gap-5">
      <StatGrid>
        <StatCard label="Pending QC" value={pending.data?.meta.total ?? 0} loading={pending.isLoading} emphasis={pending.data?.meta.total ? 'warning' : 'default'} />
        <StatCard label="Rework required" value={rework.data?.meta.total ?? 0} loading={rework.isLoading} emphasis={rework.data?.meta.total ? 'danger' : 'default'} />
        <StatCard label="Passed" value={passed.data?.meta.total ?? 0} loading={passed.isLoading} />
        <StatCard label="Failed" value={failed.data?.meta.total ?? 0} loading={failed.isLoading} />
      </StatGrid>
      <Tabs value={f.tab} onValueChange={(tab) => setF({ tab, search: '' })}>
        <TabsList label="Quality control">
          <TabsTrigger value="pending" count={pending.data?.meta.total}>Pending QC</TabsTrigger>
          <TabsTrigger value="rework" count={rework.data?.meta.total}>Rework required</TabsTrigger>
          <TabsTrigger value="passed">Passed</TabsTrigger>
          <TabsTrigger value="failed">Failed</TabsTrigger>
        </TabsList>
        <TabsContent value="pending" className="mt-4">
          <CaseQueueSection title="QC pending" subtitle="Production completed, awaiting inspection" params={{ status: ['quality_control'] }} emptyText="No pending QC cases." limit={30} />
        </TabsContent>
        <TabsContent value="rework" className="mt-4">
          <CaseQueueSection title="Rework required" subtitle="Failed inspection — back with the technician" params={{ status: ['rework'] }} emptyText="No cases waiting for rework." limit={30} />
        </TabsContent>
        <TabsContent value="passed" className="mt-4">
          <QcRecords result="passed" search={f.search} page={Number(f.page)} onSearch={(search) => setF({ search })} onPage={(p) => setF({ page: String(p) }, { resetPage: false })} />
        </TabsContent>
        <TabsContent value="failed" className="mt-4">
          <QcRecords result="failed" search={f.search} page={Number(f.page)} onSearch={(search) => setF({ search })} onPage={(p) => setF({ page: String(p) }, { resetPage: false })} />
        </TabsContent>
      </Tabs>
    </div>
  );
}
