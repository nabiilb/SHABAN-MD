import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { DELIVERY_METHOD_LABELS } from '@/lib/constants';
import { useCases } from '@/hooks/api/use-cases';
import { useDeliveries } from '@/hooks/api/use-lab';
import { usePageTitle } from '@/hooks/use-page-title';
import { useUrlState } from '@/hooks/use-url-state';
import type { DeliveryListItem } from '@/types/api';
import { formatDateTime } from '@/utils/format';
import { CaseQueueSection } from '@/components/dashboard/case-queue-section';
import { StatCard, StatGrid } from '@/components/dashboard/stat-card';
import { DataTable } from '@/components/tables/data-table';
import { FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

const DEFAULTS = { tab: 'ready', search: '', method: undefined as string | undefined, page: '1' };
const STATUS_LABEL = { ready: 'Ready', out_for_delivery: 'Out for delivery', delivered: 'Delivered' } as const;

export default function DeliveryPage() {
  usePageTitle('Delivery', 'Dispatch finished cases and record the hand-over');
  const navigate = useNavigate();
  const [f, setF] = useUrlState(DEFAULTS);
  const ready = useCases({ status: ['ready'], perPage: 1 });
  const out = useCases({ status: ['out_for_delivery'], perPage: 1 });
  const history = useDeliveries({ status: 'delivered', search: f.search || undefined, method: f.method, page: Number(f.page), perPage: 20 });

  const columns = useMemo<ColumnDef<DeliveryListItem, unknown>[]>(
    () => [
      { id: 'case', header: 'Case', cell: ({ row }) => <span className="font-mono text-[13px] font-bold text-brand">{row.original.caseNumber}</span> },
      { id: 'patient', header: 'Patient', cell: ({ row }) => <span className="font-semibold whitespace-nowrap">{row.original.patientName}</span> },
      { id: 'to', header: 'Delivered to', cell: ({ row }) => <div className="flex flex-col"><span className="whitespace-nowrap">{row.original.deliveredTo ?? row.original.clinicName}</span><span className="text-xs text-ink-3">{row.original.clinicName}</span></div> },
      { id: 'by', header: 'Received by', meta: { cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => row.original.receivedBy ?? '—' },
      { id: 'method', header: 'Method', meta: { cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => DELIVERY_METHOD_LABELS[row.original.method] },
      { id: 'courier', header: 'Courier', meta: { cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => row.original.courierName ?? '—' },
      { id: 'date', header: 'Delivery date', meta: { cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => formatDateTime(row.original.deliveredAt) },
      { id: 'status', header: 'Status', cell: ({ row }) => <Badge tone="success">{STATUS_LABEL[row.original.status]}</Badge> },
      { id: 'notes', header: 'Notes', meta: { cellClassName: 'text-[13px] text-ink-2' }, cell: ({ row }) => row.original.notes || '—' },
    ],
    [],
  );

  return (
    <div className="flex flex-col gap-5">
      <StatGrid>
        <StatCard label="Ready" value={ready.data?.meta.total ?? 0} loading={ready.isLoading} />
        <StatCard label="Out for delivery" value={out.data?.meta.total ?? 0} loading={out.isLoading} />
        <StatCard label="Delivered (all time)" value={history.data?.meta.total ?? 0} loading={history.isLoading} />
      </StatGrid>
      <Tabs value={f.tab} onValueChange={(tab) => setF({ tab })}>
        <TabsList label="Delivery">
          <TabsTrigger value="ready" count={ready.data?.meta.total}>Ready</TabsTrigger>
          <TabsTrigger value="out" count={out.data?.meta.total}>Out for delivery</TabsTrigger>
          <TabsTrigger value="delivered">Delivered</TabsTrigger>
        </TabsList>
        <TabsContent value="ready" className="mt-4">
          <CaseQueueSection title="Ready for delivery" subtitle="Passed quality control" params={{ status: ['ready'] }} emptyText="Nothing ready to deliver." limit={30} />
        </TabsContent>
        <TabsContent value="out" className="mt-4">
          <CaseQueueSection title="Out for delivery" subtitle="With a courier" params={{ status: ['out_for_delivery'] }} emptyText="Nothing on the road." limit={30} />
        </TabsContent>
        <TabsContent value="delivered" className="mt-4">
          <DataTable
            caption="Delivery history"
            columns={columns}
            data={history.data?.data}
            meta={history.data?.meta}
            getRowId={(d) => d.id}
            isLoading={history.isLoading}
            isFetching={history.isFetching}
            error={history.error}
            onRetry={() => void history.refetch()}
            onPageChange={(p) => setF({ page: String(p) }, { resetPage: false })}
            onRowClick={(d) => navigate(`/cases/${d.caseId}`)}
            emptyTitle="No deliveries recorded yet."
            toolbar={
              <ToolbarRow>
                <SearchInput value={f.search} onChange={(search) => setF({ search })} placeholder="Case, patient, receiver…" />
                <FilterSelect label="Method" value={f.method} onChange={(method) => setF({ method })} options={Object.entries(DELIVERY_METHOD_LABELS).map(([value, label]) => ({ value, label }))} />
              </ToolbarRow>
            }
            renderMobileCard={(d) => (
              <div className="flex flex-col gap-0.5">
                <span className="font-mono text-[12.5px] font-bold text-brand">{d.caseNumber}</span>
                <span className="font-semibold">{d.patientName}</span>
                <span className="text-xs text-ink-2">{d.deliveredTo} · received by {d.receivedBy}</span>
                <span className="text-xs text-ink-3">{formatDateTime(d.deliveredAt)}</span>
              </div>
            )}
          />
        </TabsContent>
      </Tabs>
    </div>
  );
}
