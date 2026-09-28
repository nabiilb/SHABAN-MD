import { Link } from 'react-router-dom';
import { AlertTriangle, CheckCircle2, Clock, Timer } from 'lucide-react';
import { PERMISSIONS } from '@/lib/permissions';
import { STATUS_META } from '@/lib/workflow';
import { useAuth } from '@/hooks/use-auth';
import { useDashboard } from '@/hooks/api/use-lab';
import { useUrlState } from '@/hooks/use-url-state';
import type { DashboardPeriod } from '@/types/api';
import { PageToolbar } from '@/components/ui/page-toolbar';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { useTechnician } from '@/hooks/api/use-directory';
import { usePageTitle } from '@/hooks/use-page-title';
import type { RoleKey } from '@/types/models';
import { formatMoney, formatPercent } from '@/utils/format';
import { BarList, ColumnChart } from '@/components/dashboard/charts';
import { CaseQueueSection } from '@/components/dashboard/case-queue-section';
import { StatCard, StatGrid } from '@/components/dashboard/stat-card';
import { Button } from '@/components/ui/button';
import { QueryError } from '@/components/ui/query-error';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { ErrorState, ProgressBar, Skeleton } from '@/components/ui/feedback';

const PERIODS: { value: DashboardPeriod; label: string }[] = [
  { value: 'today', label: 'Today' },
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: 'month', label: 'This month' },
];
const PERIOD_DEFAULTS = { period: '30d' };

const SUBTITLES: Record<RoleKey, string> = {
  super_admin: 'Who has access, and how is the system running?',
  admin: 'How is the lab performing against the 48-hour promise?',
  lab_manager: 'What needs assignment or quality control?',
  reception: 'What needs acceptance, payment or delivery?',
  technician: 'What do I need to work on right now?',
  qc: 'What needs inspection right now?',
  delivery: 'What is ready to go out?',
  client: 'Where is my case, and when will I get it?',
};

export default function DashboardPage() {
  const { user } = useAuth();
  usePageTitle('Dashboard', user ? SUBTITLES[user.role] : undefined);
  if (!user) return null;
  if (user.role === 'technician') return <TechnicianDashboard technicianId={user.technicianId ?? undefined} />;
  if (user.role === 'client') return <ClientDashboard />;
  return <OperationsDashboard role={user.role} />;
}

/* ------------------------------------------------------------------------ */

function OperationsDashboard({ role }: { role: RoleKey }) {
  const { can } = useAuth();
  const [{ period }, setUrl] = useUrlState(PERIOD_DEFAULTS);
  const { data: d, isLoading, error, refetch } = useDashboard(period as DashboardPeriod);
  const finance = can(PERMISSIONS.REPORTS_FINANCIAL);
  const periodLabel = PERIODS.find((p) => p.value === period)?.label.toLowerCase() ?? '';

  if (error) return <QueryError error={error} onRetry={() => void refetch()} />;
  const v = (n: number | undefined) => n ?? 0;

  const inLabTotal = d ? d.performance.onTime + d.performance.atRisk + d.performance.overdue : 0;

  return (
    <div className="flex flex-col gap-6">
      <PageToolbar
        actions={<SegmentedControl label="Dashboard period" value={period as DashboardPeriod} onChange={(v) => setUrl({ period: v })} options={PERIODS} />}
      >
        <p className="text-[13px] text-ink-2">
          New, completed, revenue and on-time figures cover <b className="text-ink">{periodLabel}</b>. Live counts show the lab right now.
        </p>
      </PageToolbar>
      <StatGrid>
        <StatCard loading={isLoading} label="Active cases" value={v(d?.activeCases)} to="/cases?view=open" />
        <StatCard loading={isLoading} label="New cases" value={v(d?.newCases)} hint={periodLabel} to={d ? `/cases?from=${d.periodStart}` : '/cases'} />
        <StatCard loading={isLoading} label="Due today" value={v(d?.dueToday)} emphasis={d?.dueToday ? 'warning' : 'default'} to="/cases?sla=due_today" />
        <StatCard loading={isLoading} label="At risk" value={v(d?.performance.atRisk)} emphasis={d?.performance.atRisk ? 'warning' : 'default'} hint="≤ 12h remaining" to="/cases?sla=at_risk" />
        <StatCard loading={isLoading} label="Overdue" value={v(d?.overdue)} emphasis={d?.overdue ? 'danger' : 'default'} to="/cases?sla=overdue" />
        <StatCard loading={isLoading} label="In production" value={v(d?.inProduction)} to="/production" />
        <StatCard loading={isLoading} label="Pending QC" value={v(d?.pendingQc)} to="/quality-control" />
        <StatCard loading={isLoading} label="Ready for delivery" value={v(d?.readyForDelivery)} to="/delivery" />
        <StatCard loading={isLoading} label="Completed" value={v(d?.completed)} hint={d ? `${d.completedInPeriod} delivered · ${periodLabel}` : undefined} to="/cases?view=done" />
        {finance && <StatCard loading={isLoading} label="Revenue" value={formatMoney(d?.revenue ?? 0, { compact: true })} hint={d ? `${formatMoney(d.collected ?? 0, { compact: true })} collected · ${periodLabel}` : undefined} to="/invoices" />}
        {finance && <StatCard loading={isLoading} label="Outstanding payments" value={formatMoney(d?.outstanding ?? 0, { compact: true })} emphasis={d?.outstanding ? 'warning' : 'default'} to="/invoices?status=unpaid" />}
      </StatGrid>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,380px)_minmax(0,1fr)]">
        <Card>
          <CardHeader title="48-hour performance" description="Live state of every case inside the lab window." />
          <CardBody className="flex flex-col gap-5">
            {isLoading || !d ? (
              <Skeleton className="h-48" />
            ) : (
              <>
                <div className="grid grid-cols-3 gap-2">
                  <PerfTile icon={<CheckCircle2 />} label="On time" value={d.performance.onTime} tone="success" />
                  <PerfTile icon={<Clock />} label="At risk" value={d.performance.atRisk} tone="warning" />
                  <PerfTile icon={<AlertTriangle />} label="Overdue" value={d.performance.overdue} tone="danger" />
                </div>
                {inLabTotal > 0 && (
                  <div className="flex h-2.5 gap-0.5 overflow-hidden rounded-full" aria-hidden>
                    <span className="bg-success" style={{ width: `${(d.performance.onTime / inLabTotal) * 100}%` }} />
                    <span className="bg-warning" style={{ width: `${(d.performance.atRisk / inLabTotal) * 100}%` }} />
                    <span className="bg-danger" style={{ width: `${(d.performance.overdue / inLabTotal) * 100}%` }} />
                  </div>
                )}
                <div className="grid grid-cols-2 gap-4 border-t border-line pt-4">
                  <div className="flex flex-col gap-1">
                    <span className="text-xs text-ink-3">On-time rate</span>
                    <span className="font-mono text-2xl font-bold">{formatPercent(d.performance.onTimeRate)}</span>
                    <ProgressBar value={d.performance.onTimeRate ?? 0} tone="success" label="On-time rate" />
                  </div>
                  <div className="flex flex-col gap-1">
                    <span className="flex items-center gap-1 text-xs text-ink-3"><Timer className="size-3.5" /> Avg. completion</span>
                    <span className="font-mono text-2xl font-bold">{d.performance.avgCompletionHours !== null ? `${d.performance.avgCompletionHours}h` : '—'}</span>
                    <span className="text-[11.5px] text-ink-3">Received → delivered</span>
                  </div>
                </div>
              </>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Last 14 days" description="Cases received and delivered per day." actions={can(PERMISSIONS.REPORTS_VIEW) && <Button asChild variant="outline" size="sm"><Link to="/reports">Open reports</Link></Button>} />
          <CardBody>
            {isLoading || !d ? (
              <Skeleton className="h-60" />
            ) : (
              <ColumnChart
                label="Cases received and delivered per day over the last 14 days"
                data={d.last14Days}
                xKey="date"
                xFormat={(s) => new Date(`${s}T12:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' })}
                series={[{ key: 'received', label: 'Received' }, { key: 'delivered', label: 'Delivered' }]}
              />
            )}
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader title="Open cases by stage" />
          <CardBody>
            {isLoading || !d ? <Skeleton className="h-40" /> : d.statusBreakdown.length ? <BarList items={d.statusBreakdown.map((s) => ({ label: STATUS_META[s.status].label, value: s.count }))} /> : <p className="text-sm text-ink-3">No open cases.</p>}
          </CardBody>
        </Card>
        {finance && d?.revenueByMonth ? (
          <Card>
            <CardHeader title="Revenue — last 6 months" description="Invoiced vs. collected." />
            <CardBody>
              <ColumnChart label="Invoiced and collected revenue per month" data={d.revenueByMonth} xKey="month" format={(n) => formatMoney(n, { compact: true })} series={[{ key: 'invoiced', label: 'Invoiced' }, { key: 'collected', label: 'Collected' }]} height={200} />
            </CardBody>
          </Card>
        ) : (
          <Card>
            <CardHeader title="Pipeline" />
            <CardBody className="grid grid-cols-2 gap-3">
              <StatCard loading={isLoading} label="Awaiting acceptance" value={v(d?.awaitingAcceptance)} to="/cases?status=submitted" />
              <StatCard loading={isLoading} label="Ready for delivery" value={v(d?.readyForDelivery)} to="/delivery" />
            </CardBody>
          </Card>
        )}
      </div>

      {role === 'reception' && <CaseQueueSection title="New cases" subtitle="Review, accept and take payment" params={{ status: ['submitted', 'correction'] }} emptyText="No new submissions." viewAllHref="/cases?status=submitted" />}
      {(role === 'lab_manager' || role === 'admin') && <CaseQueueSection title="Waiting for assignment" subtitle="Accepted, no technician yet" params={{ status: ['received', 'review'] }} emptyText="Every accepted case is assigned." viewAllHref="/production" />}
      {role === 'qc' && <CaseQueueSection title="QC pending" subtitle="Production completed, awaiting inspection" params={{ status: ['quality_control'] }} emptyText="No cases waiting for QC." viewAllHref="/quality-control" />}
      {(role === 'delivery' || role === 'reception') && <CaseQueueSection title="Ready for delivery" subtitle="Passed quality control" params={{ status: ['ready', 'out_for_delivery'] }} emptyText="Nothing ready to deliver." viewAllHref="/delivery" />}
      <CaseQueueSection title="At risk" subtitle="Under 12 hours remaining or overdue" params={{ sla: 'at_risk' }} emptyText="All active cases are on track." viewAllHref="/cases?sla=at_risk" />
      <CaseQueueSection title="Overdue" subtitle="Past the 48-hour deadline" params={{ sla: 'overdue' }} emptyText="No overdue cases." viewAllHref="/cases?sla=overdue" />
    </div>
  );
}

function PerfTile({ icon, label, value, tone }: { icon: React.ReactNode; label: string; value: number; tone: 'success' | 'warning' | 'danger' }) {
  const cls = { success: 'bg-success-bg text-success', warning: 'bg-warning-bg text-warning', danger: 'bg-danger-bg text-danger' }[tone];
  return (
    <div className={`flex flex-col gap-1 rounded-md px-3 py-2.5 ${cls}`}>
      <span className="flex items-center gap-1.5 text-[11.5px] font-bold [&_svg]:size-3.5">{icon}{label}</span>
      <span className="font-mono text-2xl font-bold">{value}</span>
    </div>
  );
}

/* ------------------------------------------------------------------------ */

function TechnicianDashboard({ technicianId }: { technicianId?: string }) {
  const { data: t, isLoading, error, refetch } = useTechnician(technicianId);
  if (!technicianId) return <ErrorState title="No technician profile" message="Your account is not linked to a technician profile. Ask an administrator to link it." />;
  if (error) return <QueryError error={error} onRetry={() => void refetch()} />;
  return (
    <div className="flex flex-col gap-6">
      <StatGrid>
        <StatCard loading={isLoading} label="My active cases" value={t?.activeCases ?? 0} />
        <StatCard loading={isLoading} label="Due today" value={t?.dueToday ?? 0} emphasis={t?.dueToday ? 'warning' : 'default'} />
        <StatCard loading={isLoading} label="Overdue" value={t?.overdue ?? 0} emphasis={t?.overdue ? 'danger' : 'default'} />
        <StatCard loading={isLoading} label="QC pending" value={t?.qcPending ?? 0} />
        <StatCard loading={isLoading} label="Completed" value={t?.completedCases ?? 0} hint={t?.onTimeRate != null ? `${formatPercent(t.onTimeRate)} on time` : undefined} />
      </StatGrid>
      <CaseQueueSection title="Rework required" subtitle="Returned by quality control" params={{ status: ['rework'] }} emptyText="No rework — nice." />
      <CaseQueueSection title="In production" subtitle="Sorted by urgency" params={{ status: ['in_production'] }} emptyText="Nothing in production." limit={9} />
      <CaseQueueSection title="Assigned to me" subtitle="Not started yet" params={{ status: ['assigned'] }} emptyText="No new assignments." />
      <CaseQueueSection title="With quality control" subtitle="Submitted, awaiting inspection" params={{ status: ['quality_control'] }} emptyText="Nothing with QC." />
    </div>
  );
}

function ClientDashboard() {
  const { data: d, isLoading } = useDashboard();
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <StatGrid className="flex-1">
          <StatCard loading={isLoading} label="Active cases" value={(d?.activeCases ?? 0) + (d?.awaitingAcceptance ?? 0)} />
          <StatCard loading={isLoading} label="In production" value={d?.inProduction ?? 0} />
          <StatCard loading={isLoading} label="Ready / on the way" value={d?.readyForDelivery ?? 0} />
          <StatCard loading={isLoading} label="Completed" value={d?.completed ?? 0} />
        </StatGrid>
      </div>
      <div>
        <Button asChild size="lg">
          <Link to="/cases/new">Submit a new case</Link>
        </Button>
      </div>
      <CaseQueueSection title="Action needed" subtitle="Waiting on you" params={{ status: ['delivered', 'correction'] }} emptyText="Nothing needs your attention right now." />
      <CaseQueueSection title="In progress" subtitle="Live 48-hour countdown" params={{ status: ['submitted', 'received', 'review', 'assigned', 'in_production', 'rework', 'quality_control', 'ready', 'out_for_delivery'] }} emptyText="No cases in the lab." limit={9} viewAllHref="/cases?view=open" />
    </div>
  );
}
