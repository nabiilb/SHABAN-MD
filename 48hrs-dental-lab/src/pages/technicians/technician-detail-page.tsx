import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { Pencil } from 'lucide-react';
import { PERMISSIONS } from '@/lib/permissions';
import { useTechnician } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { formatPercent } from '@/utils/format';
import { DetailHeader } from '@/components/directory/detail-header';
import { TechnicianFormDialog } from '@/components/directory/directory-forms';
import { CaseMiniTable } from '@/components/directory/relation-summary';
import { StatCard, StatGrid } from '@/components/dashboard/stat-card';
import { Button } from '@/components/ui/button';
import { QueryError } from '@/components/ui/query-error';
import { ActiveBadge } from '@/components/ui/record-badges';
import { Card, CardBody, CardHeader, Field } from '@/components/ui/card';
import { PageLoader } from '@/components/ui/feedback';

export default function TechnicianDetailPage() {
  const { id } = useParams();
  const { data: t, isLoading, error, refetch } = useTechnician(id);
  const { can } = useAuth();
  const [editing, setEditing] = useState(false);
  usePageTitle(t?.name ?? 'Technician', t ? `${t.specialty} · performance` : undefined);

  if (isLoading) return <PageLoader />;
  if (error || !t) return <QueryError error={error} onRetry={() => void refetch()} />;

  return (
    <div className="flex flex-col gap-4">
      <DetailHeader
        name={t.name}
        subtitle={t.specialty}
        badges={<ActiveBadge active={t.active} />}
        actions={can(PERMISSIONS.TECHNICIANS_MANAGE) && <Button variant="outline" onClick={() => setEditing(true)}><Pencil /> Edit</Button>}
      >
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
          <Field label="Email">{t.email}</Field>
          <Field label="Phone">{t.phone}</Field>
          <Field label="Avg. production time" mono>{t.avgProductionHours !== null ? `${t.avgProductionHours}h` : '—'}</Field>
        </dl>
      </DetailHeader>
      <StatGrid>
        <StatCard label="Active cases" value={t.activeCases} />
        <StatCard label="Due today" value={t.dueToday} emphasis={t.dueToday ? 'warning' : 'default'} />
        <StatCard label="Overdue" value={t.overdue} emphasis={t.overdue ? 'danger' : 'default'} />
        <StatCard label="QC pending" value={t.qcPending} />
        <StatCard label="Completed" value={t.completedCases} />
        <StatCard label="On-time rate" value={formatPercent(t.onTimeRate)} emphasis={t.onTimeRate !== null && t.onTimeRate >= 0.9 ? 'success' : 'default'} />
        <StatCard label="QC failures" value={t.qcFailures} emphasis={t.qcFailures ? 'warning' : 'default'} />
      </StatGrid>
      <Card>
        <CardHeader title="Assigned cases" description="Sorted by deadline." />
        <CardBody><CaseMiniTable cases={t.activeCaseList} emptyTitle="No technicians assigned work right now." /></CardBody>
      </Card>
      <Card>
        <CardHeader title="Recently completed" />
        <CardBody><CaseMiniTable cases={t.recentCompleted} emptyTitle="No completed cases yet." /></CardBody>
      </Card>
      <TechnicianFormDialog open={editing} onOpenChange={(o) => { setEditing(o); if (!o) void refetch(); }} record={t} />
    </div>
  );
}
