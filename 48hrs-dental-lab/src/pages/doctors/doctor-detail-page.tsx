import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Pencil } from 'lucide-react';
import { PERMISSIONS } from '@/lib/permissions';
import { useDoctor } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { errorMessage } from '@/services/api/errors';
import { formatDate } from '@/utils/format';
import { DetailHeader } from '@/components/directory/detail-header';
import { DoctorFormDialog } from '@/components/directory/directory-forms';
import { CaseMiniTable, RelationStatsGrid } from '@/components/directory/relation-summary';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardBody, CardHeader, Field } from '@/components/ui/card';
import { ErrorState, PageLoader } from '@/components/ui/feedback';

export default function DoctorDetailPage() {
  const { id } = useParams();
  const { data: d, isLoading, error, refetch } = useDoctor(id);
  const { can } = useAuth();
  const [editing, setEditing] = useState(false);
  usePageTitle(d?.name ?? 'Doctor', d?.clinicName);

  if (isLoading) return <PageLoader />;
  if (error || !d) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />;

  return (
    <div className="flex flex-col gap-4">
      <DetailHeader
        backTo="/doctors"
        backLabel="Doctors"
        name={d.name}
        subtitle={d.specialty}
        badges={<Badge tone={d.status === 'active' ? 'success' : 'neutral'}>{d.status === 'active' ? 'Active' : 'Inactive'}</Badge>}
        actions={can(PERMISSIONS.DOCTORS_MANAGE) && <Button variant="outline" onClick={() => setEditing(true)}><Pencil /> Edit</Button>}
      >
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Field label="Clinic">{can(PERMISSIONS.CLINICS_VIEW) ? <Link className="hover:underline" to={`/clinics/${d.clinicId}`}>{d.clinicName}</Link> : d.clinicName}</Field>
          <Field label="Phone">{d.phone}</Field>
          <Field label="Email">{d.email || '—'}</Field>
          <Field label="Since">{formatDate(d.createdAt)}</Field>
        </dl>
      </DetailHeader>
      <RelationStatsGrid stats={d.stats} />
      <Card>
        <CardHeader title="Recent cases" actions={<Button asChild variant="outline" size="sm"><Link to={`/cases?doctorId=${d.id}`}>All cases</Link></Button>} />
        <CardBody><CaseMiniTable cases={d.recentCases} showClinic={false} /></CardBody>
      </Card>
      <DoctorFormDialog open={editing} onOpenChange={(o) => { setEditing(o); if (!o) void refetch(); }} record={d} />
    </div>
  );
}
