import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import { useAuth } from '@/hooks/use-auth';
import type { RelationStats } from '@48hrs/shared/types';
import type { CaseListItem } from '@48hrs/shared/types';
import { formatDate, formatMoney } from '@/utils/format';
import { PaymentBadge, StatusBadge } from '@/components/cases/badges';
import { SlaCell } from '@/components/cases/sla';
import { StatCard, StatGrid } from '@/components/dashboard/stat-card';
import { DataTable } from '@/components/tables/data-table';

export function RelationStatsGrid({ stats }: { stats: RelationStats }) {
  const { can } = useAuth();
  const money = can([PERMISSIONS.INVOICES_VIEW, PERMISSIONS.PAYMENTS_VIEW], 'any');
  return (
    <StatGrid>
      <StatCard label="Total cases" value={stats.totalCases} />
      <StatCard label="Active cases" value={stats.activeCases} />
      <StatCard label="Completed" value={stats.completedCases} />
      <StatCard label="Overdue" value={stats.overdueCases} emphasis={stats.overdueCases ? 'danger' : 'default'} />
      {money && <StatCard label="Billed" value={formatMoney(stats.billed)} />}
      {money && <StatCard label="Outstanding" value={formatMoney(stats.outstanding)} emphasis={stats.outstanding ? 'warning' : 'default'} />}
    </StatGrid>
  );
}

/** Compact, read-only case table for detail pages. */
export function CaseMiniTable({ cases, emptyTitle = 'No cases yet.', showClinic = true }: { cases: CaseListItem[]; emptyTitle?: string; showClinic?: boolean }) {
  const navigate = useNavigate();
  const { can } = useAuth();
  const money = can([PERMISSIONS.INVOICES_VIEW, PERMISSIONS.PAYMENTS_VIEW], 'any');
  const columns = useMemo<ColumnDef<CaseListItem, unknown>[]>(
    () => [
      { id: 'n', header: 'Case ID', cell: ({ row }) => <span className="font-mono text-[13px] font-bold text-brand">{row.original.caseNumber}</span> },
      { id: 'p', header: 'Patient', cell: ({ row }) => <span className="font-semibold whitespace-nowrap">{row.original.patient.name}</span> },
      ...(showClinic ? ([{ id: 'c', header: 'Clinic', cell: ({ row }) => <span className="whitespace-nowrap text-ink-2">{row.original.clinic.name}</span> }] as ColumnDef<CaseListItem, unknown>[]) : []),
      { id: 's', header: 'Service', cell: ({ row }) => <span className="whitespace-nowrap">{row.original.restorationType} · {row.original.units}</span> },
      { id: 'r', header: 'Created', meta: { cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => formatDate(row.original.createdAt) },
      { id: 'd', header: 'Due / SLA', cell: ({ row }) => <SlaCell c={row.original} /> },
      { id: 'st', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} /> },
      ...(money ? ([{ id: 'pay', header: 'Payment', cell: ({ row }) => <PaymentBadge status={row.original.paymentStatus} /> }] as ColumnDef<CaseListItem, unknown>[]) : []),
    ],
    [money, showClinic],
  );
  return (
    <DataTable
      columns={columns}
      data={cases}
      getRowId={(c) => c.id}
      onRowClick={(c) => navigate(`/cases/${c.id}`)}
      emptyTitle={emptyTitle}
      renderMobileCard={(c) => (
        <div className="flex items-start justify-between gap-2">
          <div className="flex flex-col">
            <span className="font-mono text-[12.5px] font-bold text-brand">{c.caseNumber}</span>
            <span className="font-semibold">{c.patient.name}</span>
            <span className="text-xs text-ink-2">{c.restorationType}</span>
          </div>
          <StatusBadge status={c.status} />
        </div>
      )}
    />
  );
}
