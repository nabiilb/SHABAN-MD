import type { ReactNode } from 'react';
import { Download } from 'lucide-react';
import { CASE_TYPE_LABELS } from '@48hrs/shared/constants';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import { ALL_STATUSES, STATUS_META } from '@48hrs/shared/workflow';
import { useClinics, useDoctors, useTechnicians } from '@/hooks/api/use-directory';
import { useReport } from '@/hooks/api/use-lab';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { useUrlState } from '@/hooks/use-url-state';
import type { ReportFilters } from '@48hrs/shared/types';
import type { CaseStatus, CaseType } from '@48hrs/shared/types';
import { daysAgo, localDay } from '@48hrs/shared/dates';
import { downloadCsv } from '@/utils/download';
import { formatMoney, formatPercent } from '@/utils/format';
import { BarList, ColumnChart } from '@/components/dashboard/charts';
import { StatCard, StatGrid } from '@/components/dashboard/stat-card';
import { DateFilter, FilterSelect } from '@/components/tables/toolbar';
import { Button } from '@/components/ui/button';
import { QueryError } from '@/components/ui/query-error';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { EmptyState, Skeleton } from '@/components/ui/feedback';
import { SimpleTable } from '@/components/ui/simple-table';

const PRESETS = [
  { key: '7', label: 'Last 7 days' },
  { key: '30', label: 'Last 30 days' },
  { key: '90', label: 'Last 90 days' },
  { key: 'month', label: 'This month' },
];

function presetRange(key: string) {
  const today = new Date();
  if (key === 'month') return { from: localDay(new Date(today.getFullYear(), today.getMonth(), 1)), to: localDay(today) };
  return { from: localDay(daysAgo(Number(key) - 1)), to: localDay(today) };
}

const DEFAULTS = { preset: '30', from: undefined as string | undefined, to: undefined as string | undefined, technicianId: undefined as string | undefined, doctorId: undefined as string | undefined, clinicId: undefined as string | undefined, status: undefined as string | undefined, caseType: undefined as string | undefined };

function ReportTable({ title, headers, rows, csvName, empty = 'No data for these filters.' }: { title: string; headers: string[]; rows: ReactNode[][]; csvName: string; empty?: string }) {
  return (
    <Card>
      <CardHeader
        title={title}
        actions={
          <Button variant="outline" size="sm" disabled={!rows.length} onClick={() => downloadCsv(`${csvName}.csv`, headers, rows.map((r) => r.map((c) => (typeof c === 'string' || typeof c === 'number' ? c : ''))))}>
            <Download /> CSV
          </Button>
        }
      />
      <CardBody className="pt-3">
        <SimpleTable
          caption={title}
          rows={rows}
          getKey={(_, i) => String(i)}
          empty={empty}
          columns={headers.map((h, j) => ({ header: h, align: j ? 'right' : 'left', className: j ? 'font-mono' : 'font-semibold', cell: (r: ReactNode[]) => r[j] }))}
        />
      </CardBody>
    </Card>
  );
}

export default function ReportsPage() {
  usePageTitle('Reports', 'Volume, turnaround, technicians, clinics and revenue');
  const { can } = useAuth();
  const [f, setF, resetF] = useUrlState(DEFAULTS);
  const range = f.preset === 'custom' ? { from: f.from ?? localDay(daysAgo(29)), to: f.to ?? localDay(new Date()) } : presetRange(f.preset);
  const filters: ReportFilters = { ...range, technicianId: f.technicianId, doctorId: f.doctorId, clinicId: f.clinicId, status: f.status as CaseStatus | undefined, caseType: f.caseType as CaseType | undefined };
  const { data: r, isLoading, isFetching, error, refetch } = useReport(filters);
  const techs = useTechnicians({ perPage: 100 }, can(PERMISSIONS.TECHNICIANS_VIEW));
  const doctors = useDoctors({ perPage: 200 }, can(PERMISSIONS.DOCTORS_VIEW));
  const clinics = useClinics({ perPage: 200 }, can(PERMISSIONS.CLINICS_VIEW));
  const finance = can(PERMISSIONS.REPORTS_FINANCIAL);
  const dayFmt = (s: string) => new Date(`${s}T12:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });

  return (
    <div className="flex flex-col gap-5">
      <Card>
        <CardBody className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Date range">
            {PRESETS.map((p) => (
              <Button key={p.key} size="sm" variant={f.preset === p.key ? 'primary' : 'outline'} onClick={() => setF({ preset: p.key, from: undefined, to: undefined })}>{p.label}</Button>
            ))}
            <Button size="sm" variant={f.preset === 'custom' ? 'primary' : 'outline'} onClick={() => setF({ preset: 'custom', from: range.from, to: range.to })}>Custom</Button>
            {f.preset === 'custom' && (
              <>
                <DateFilter label="From" value={f.from} onChange={(v) => setF({ from: v })} />
                <DateFilter label="To" value={f.to} onChange={(v) => setF({ to: v })} />
              </>
            )}
            {isFetching && !isLoading && <span className="text-xs text-ink-3">Updating…</span>}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {techs.data && <FilterSelect label="Technician" value={f.technicianId} onChange={(v) => setF({ technicianId: v })} options={techs.data.data.map((t) => ({ value: t.id, label: t.name }))} />}
            {clinics.data && <FilterSelect label="Clinic" value={f.clinicId} onChange={(v) => setF({ clinicId: v })} options={clinics.data.data.map((k) => ({ value: k.id, label: k.name }))} />}
            {doctors.data && <FilterSelect label="Doctor" value={f.doctorId} onChange={(v) => setF({ doctorId: v })} options={doctors.data.data.map((d) => ({ value: d.id, label: d.name }))} />}
            <FilterSelect label="Status" value={f.status} onChange={(v) => setF({ status: v })} options={ALL_STATUSES.map((s) => ({ value: s, label: STATUS_META[s].label }))} />
            <FilterSelect label="Case type" value={f.caseType} onChange={(v) => setF({ caseType: v })} options={Object.entries(CASE_TYPE_LABELS).map(([value, label]) => ({ value, label }))} />
            <Button variant="ghost" size="sm" onClick={resetF}>Reset</Button>
          </div>
        </CardBody>
      </Card>

      {error ? (
        <QueryError error={error} onRetry={() => void refetch()} />
      ) : isLoading || !r ? (
        <div className="grid gap-3 sm:grid-cols-4">{Array.from({ length: 8 }, (_, i) => <Skeleton key={i} className="h-24" />)}</div>
      ) : r.totals.cases === 0 ? (
        <EmptyState title="No cases in this range." description="Widen the date range or remove a filter." />
      ) : (
        <>
          <StatGrid>
            <StatCard label="Cases" value={r.totals.cases} />
            <StatCard label="Completed" value={r.totals.completed} />
            <StatCard label="Open" value={r.totals.open} />
            <StatCard label="Overdue / late" value={r.totals.overdue} emphasis={r.totals.overdue ? 'danger' : 'default'} />
            <StatCard label="On-time rate" value={formatPercent(r.totals.onTimeRate)} emphasis={(r.totals.onTimeRate ?? 0) >= 0.9 ? 'success' : 'warning'} />
            <StatCard label="Avg. turnaround" value={r.totals.avgTurnaroundHours !== null ? `${r.totals.avgTurnaroundHours}h` : '—'} />
            {finance && <StatCard label="Revenue" value={formatMoney(r.totals.revenue)} />}
            {finance && <StatCard label="Outstanding" value={formatMoney(r.totals.outstanding)} emphasis={r.totals.outstanding ? 'warning' : 'default'} />}
          </StatGrid>

          <Card>
            <CardHeader title="Daily cases" description={`${dayFmt(range.from)} – ${dayFmt(range.to)}`} />
            <CardBody>
              <ColumnChart label="Cases received and completed per day" data={r.daily} xKey="label" xFormat={dayFmt} series={[{ key: 'received', label: 'Received' }, { key: 'completed', label: 'Completed' }]} height={260} />
            </CardBody>
          </Card>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader title="Cases by status" />
              <CardBody><BarList items={r.byStatus.map((s) => ({ label: STATUS_META[s.status].label, value: s.count }))} /></CardBody>
            </Card>
            <Card>
              <CardHeader title="Cases by type" />
              <CardBody><BarList items={r.byCaseType.map((t) => ({ label: CASE_TYPE_LABELS[t.caseType], value: t.count }))} /></CardBody>
            </Card>
          </div>

          <ReportTable
            title="Monthly cases"
            csvName="monthly-cases"
            headers={['Month', 'Received', 'Completed', 'Overdue / late', ...(finance ? ['Revenue'] : [])]}
            rows={r.monthly.map((m) => [m.label, m.received, m.completed, m.overdue, ...(finance ? [formatMoney(m.revenue)] : [])])}
          />
          <ReportTable
            title="Technician performance"
            csvName="technician-performance"
            headers={['Technician', 'Assigned', 'Completed', 'On time', 'Avg. production (h)', 'QC failures']}
            rows={r.technicians.map((t) => [t.name, t.assigned, t.completed, formatPercent(t.onTimeRate), t.avgProductionHours ?? '—', t.qcFailures])}
          />
          <ReportTable
            title="Doctor / clinic cases"
            csvName="clinic-cases"
            headers={['Clinic', 'Cases', 'Completed', ...(finance ? ['Revenue', 'Outstanding'] : [])]}
            rows={r.clinics.map((k) => [k.name, k.cases, k.completed, ...(finance ? [formatMoney(k.revenue), formatMoney(k.outstanding)] : [])])}
          />
          <ReportTable
            title="Production performance"
            csvName="production-stages"
            headers={['Stage', 'Average hours', 'Samples']}
            rows={r.stages.map((s) => [s.stage, s.avgHours ?? '—', s.samples])}
          />
        </>
      )}
    </div>
  );
}
