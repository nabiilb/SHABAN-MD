import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { FilePlus2, Pencil, Trash2 } from 'lucide-react';
import { PERMISSIONS } from '@/lib/permissions';
import { DONE_STATUSES, STATUS_META } from '@/lib/workflow';
import { useDeletePatient, usePatient } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { formatDate } from '@/utils/format';
import { CaseMiniTable, RelationStatsGrid } from '@/components/directory/relation-summary';
import { DetailHeader } from '@/components/directory/detail-header';
import { PatientFormDialog } from '@/components/directory/directory-forms';
import { Button } from '@/components/ui/button';
import { QueryError } from '@/components/ui/query-error';
import { Card, CardBody, CardHeader, Field } from '@/components/ui/card';
import { useConfirmedDelete } from '@/components/ui/use-confirmed-delete';
import { PageLoader } from '@/components/ui/feedback';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

export default function PatientDetailPage() {
  const { id } = useParams();
  const { data: p, isLoading, error, refetch } = usePatient(id);
  const { can } = useAuth();
  const navigate = useNavigate();
  const [editing, setEditing] = useState(false);
  const del = useDeletePatient();
  const { request: requestDelete, element: deleteDialog } = useConfirmedDelete<{ id: string; name: string }>({
    title: 'Delete patient?',
    confirmLabel: 'Delete patient',
    describe: (r) => <>Delete <b>{r.name}</b>? Patients with cases cannot be deleted — their records are part of the case history.</>,
    remove: (r) => del.mutateAsync(r.id),
    successMessage: () => 'Patient deleted',
    onDeleted: () => navigate('/patients', { replace: true }),
  });
  usePageTitle(p?.name ?? 'Patient', p ? `Patient ${p.code}` : undefined);

  if (isLoading) return <PageLoader />;
  if (error || !p) return <QueryError error={error} onRetry={() => void refetch()} />;
  const current = p.recentCases.filter((c) => STATUS_META[c.status].open);
  const completed = p.recentCases.filter((c) => DONE_STATUSES.includes(c.status));

  return (
    <div className="flex flex-col gap-4">
      <DetailHeader
        name={p.name}
        subtitle={<span className="font-mono">{p.code}</span>}
        actions={
          <>
            {can([PERMISSIONS.CASES_CREATE, PERMISSIONS.CASES_SUBMIT], 'any') && <Button asChild variant="secondary"><Link to="/cases/new"><FilePlus2 /> New case</Link></Button>}
            {can(PERMISSIONS.PATIENTS_EDIT) && <Button variant="outline" onClick={() => setEditing(true)}><Pencil /> Edit</Button>}
            {can(PERMISSIONS.PATIENTS_DELETE) && <Button variant="danger-soft" onClick={() => p && requestDelete(p)}><Trash2 /> Delete</Button>}
          </>
        }
      >
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
          <Field label="Phone">{p.phone || '—'}</Field>
          <Field label="Email">{p.email || '—'}</Field>
          <Field label="Gender">{p.gender ? p.gender[0].toUpperCase() + p.gender.slice(1) : '—'}</Field>
          <Field label="Date of birth">{formatDate(p.dateOfBirth)}</Field>
          <Field label="Clinic">{p.clinicName ?? '—'}</Field>
          <Field label="Patient since">{formatDate(p.createdAt)}</Field>
        </dl>
        {p.notes && <p className="rounded-md bg-gray-50 px-3.5 py-2.5 text-[13px]">{p.notes}</p>}
      </DetailHeader>

      <RelationStatsGrid stats={p.stats} />

      <Card>
        <CardHeader title="Case history" />
        <CardBody>
          <Tabs defaultValue="current">
            <TabsList label="Case history">
              <TabsTrigger value="current" count={current.length}>Current</TabsTrigger>
              <TabsTrigger value="completed" count={completed.length}>Completed</TabsTrigger>
              <TabsTrigger value="all" count={p.recentCases.length}>All</TabsTrigger>
            </TabsList>
            <TabsContent value="current" className="mt-4"><CaseMiniTable cases={current} emptyTitle="No current cases." /></TabsContent>
            <TabsContent value="completed" className="mt-4"><CaseMiniTable cases={completed} emptyTitle="No completed cases." /></TabsContent>
            <TabsContent value="all" className="mt-4"><CaseMiniTable cases={p.recentCases} /></TabsContent>
          </Tabs>
        </CardBody>
      </Card>

      <PatientFormDialog open={editing} onOpenChange={(o) => { setEditing(o); if (!o) void refetch(); }} record={p} />
      {deleteDialog}
    </div>
  );
}
