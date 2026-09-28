import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Pencil, Plus } from 'lucide-react';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import { useClinic } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { DetailHeader } from '@/components/directory/detail-header';
import { ClinicFormDialog, DoctorFormDialog } from '@/components/directory/directory-forms';
import { CaseMiniTable, RelationStatsGrid } from '@/components/directory/relation-summary';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { QueryError } from '@/components/ui/query-error';
import { ActiveBadge } from '@/components/ui/record-badges';
import { Card, CardBody, CardHeader, Field } from '@/components/ui/card';
import { PageLoader } from '@/components/ui/feedback';

export default function ClinicDetailPage() {
  const { id } = useParams();
  const { data: k, isLoading, error, refetch } = useClinic(id);
  const { can } = useAuth();
  const [editing, setEditing] = useState(false);
  const [addingDoctor, setAddingDoctor] = useState(false);
  usePageTitle(k?.name ?? 'Clinic', k?.address);

  if (isLoading) return <PageLoader />;
  if (error || !k) return <QueryError error={error} onRetry={() => void refetch()} />;

  return (
    <div className="flex flex-col gap-4">
      <DetailHeader
        name={k.name}
        subtitle={k.address}
        badges={<ActiveBadge active={k.status === 'active'} />}
        actions={can(PERMISSIONS.CLINICS_EDIT) && <Button variant="outline" onClick={() => setEditing(true)}><Pencil /> Edit</Button>}
      >
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Field label="Contact">{k.contactPerson || '—'}</Field>
          <Field label="Phone">{k.phone}</Field>
          <Field label="Email">{k.email || '—'}</Field>
          <Field label="Doctors" mono>{k.doctorCount}</Field>
        </dl>
        {k.notes && <p className="rounded-md bg-gray-50 px-3.5 py-2.5 text-[13px]">{k.notes}</p>}
      </DetailHeader>
      <RelationStatsGrid stats={k.stats} />
      <div className="grid gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
        <Card>
          <CardHeader title="Doctors" actions={can(PERMISSIONS.DOCTORS_CREATE) && <Button variant="outline" size="sm" onClick={() => setAddingDoctor(true)}><Plus /> Add</Button>} />
          <CardBody>
            {k.doctors.length === 0 ? (
              <p className="text-[13px] text-ink-3">No doctors yet.</p>
            ) : (
              <ul className="divide-y divide-line">
                {k.doctors.map((d) => (
                  <li key={d.id} className="flex items-center justify-between gap-2 py-2.5">
                    <div className="flex flex-col">
                      <Link to={`/doctors/${d.id}`} className="text-sm font-semibold hover:underline">{d.name}</Link>
                      <span className="text-xs text-ink-3">{d.specialty} · {d.phone}</span>
                    </div>
                    {d.status !== 'active' && <Badge>Inactive</Badge>}
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Recent cases" actions={<Button asChild variant="outline" size="sm"><Link to={`/cases?clinicId=${k.id}`}>All cases</Link></Button>} />
          <CardBody><CaseMiniTable cases={k.recentCases} showClinic={false} /></CardBody>
        </Card>
      </div>
      <ClinicFormDialog open={editing} onOpenChange={(o) => { setEditing(o); if (!o) void refetch(); }} record={k} />
      <DoctorFormDialog open={addingDoctor} onOpenChange={(o) => { setAddingDoctor(o); if (!o) void refetch(); }} defaultClinicId={k.id} />
    </div>
  );
}
