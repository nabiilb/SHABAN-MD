import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { CheckCircle2, Pencil, Printer, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { DENTURE_TYPES } from '@/lib/billing';
import { CASE_TYPE_LABELS, DELIVERY_METHOD_LABELS, DELIVERY_STATUS_LABELS, QC_ISSUE_LABELS } from '@/lib/constants';
import { PERMISSIONS } from '@/lib/permissions';
import { formatDuration } from '@/lib/sla';
import { STATUS_META, nextActorLabel, stageLabel } from '@/lib/workflow';
import { useAddCaseNote, useCase } from '@/hooks/api/use-cases';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { ApiError, errorMessage } from '@/services/api/errors';
import type { CaseDetail } from '@/types/models';
import { formatDate, formatDateTime, formatMoney, formatTeeth } from '@/utils/format';
import { PaymentBadge, PriorityBadge, StatusBadge } from '@/components/cases/badges';
import { CaseActions } from '@/components/cases/case-actions';
import { CaseAttachments } from '@/components/cases/case-attachments';
import { CaseHistoryList, CaseStepper } from '@/components/cases/case-timeline';
import { EditCaseDialog } from '@/components/cases/edit-case-dialog';
import { SlaCountdown } from '@/components/cases/sla';
import { ToothChart } from '@/components/cases/tooth-chart';
import { RecordPaymentDialog } from '@/components/payments/record-payment-dialog';
import { Badge } from '@/components/ui/badge';
import { PageToolbar } from '@/components/ui/page-toolbar';
import { Button } from '@/components/ui/button';
import { QueryError } from '@/components/ui/query-error';
import { Card, CardBody, CardHeader, Field } from '@/components/ui/card';
import { Alert, EmptyState, PageLoader, ProgressBar } from '@/components/ui/feedback';
import { Textarea } from '@/components/ui/input';

export default function CaseDetailPage() {
  const { id } = useParams();
  const { data: c, isLoading, error, refetch } = useCase(id);
  usePageTitle(c ? c.caseNumber : 'Case', c ? `${c.patient.name} · ${c.restorationType}` : undefined);

  if (isLoading) return <PageLoader label="Loading case…" />;
  if (error) {
    if (error instanceof ApiError && error.isNotFound) {
      return <EmptyState title="Case not found" description="It may have been deleted, or it is outside your access." action={<Button asChild variant="outline"><Link to="/cases">Back to cases</Link></Button>} />;
    }
    return <QueryError error={error} onRetry={() => void refetch()} />;
  }
  if (!c) return null;
  return <CaseDetailView c={c} />;
}

function CaseDetailView({ c }: { c: CaseDetail }) {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const [payOpen, setPayOpen] = useState(false);
  const editOpen = params.get('edit') === '1';
  const setEditOpen = (o: boolean) => setParams(o ? { edit: '1' } : {}, { replace: true });
  const meta = STATUS_META[c.status];
  const canMoney = !!c.invoice;
  const lastQc = c.qualityChecks[c.qualityChecks.length - 1];
  const delivery = c.deliveries[c.deliveries.length - 1];
  const prodMs = c.productionStartedAt ? (c.productionCompletedAt ? new Date(c.productionCompletedAt).getTime() : Date.now()) - new Date(c.productionStartedAt).getTime() : null;

  return (
    <div className="flex flex-col gap-4">
      <PageToolbar
        className="no-print"
        actions={
          <>
            <Button variant="outline" size="sm" onClick={() => window.print()}><Printer /> Print</Button>
            {can(PERMISSIONS.CASES_EDIT) && meta.open && <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}><Pencil /> Edit</Button>}
          </>
        }
      />

      {/* Header */}
      <Card>
        <CardBody className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
          <div className="flex min-w-0 flex-col gap-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="flex min-w-0 flex-col gap-1">
                <span className="font-mono text-sm font-bold tracking-[.02em] text-brand">{c.caseNumber}</span>
                <h2 className="text-2xl leading-tight font-bold text-ink">{c.patient.name}</h2>
                <p className="text-[13.5px] text-ink-2">
                  {can(PERMISSIONS.DOCTORS_VIEW) ? <Link className="hover:underline" to={`/doctors/${c.doctor.id}`}>{c.doctor.name}</Link> : c.doctor.name}
                  {' · '}
                  {can(PERMISSIONS.CLINICS_VIEW) ? <Link className="hover:underline" to={`/clinics/${c.clinic.id}`}>{c.clinic.name}</Link> : c.clinic.name}
                </p>
              </div>
              <div className="flex flex-wrap gap-1.5">
                <StatusBadge status={c.status} />
                <PriorityBadge priority={c.priority} />
                {canMoney && <PaymentBadge status={c.paymentStatus} />}
              </div>
            </div>
            <dl className="grid grid-cols-2 gap-3 md:grid-cols-3">
              <Field label="Case type">{CASE_TYPE_LABELS[c.caseType]} · {c.restorationType}</Field>
              <Field label="Units" mono>{c.units}</Field>
              <Field label="Current stage">{stageLabel(c.status)}</Field>
              <Field label="Technician">{c.technician?.name ?? 'Not assigned'}</Field>
              <Field label="Received">{c.receivedAt ? formatDateTime(c.receivedAt) : 'Awaiting intake'}</Field>
              <Field label="Deadline">{c.dueAt ? formatDateTime(c.dueAt) : 'Starts on acceptance'}</Field>
            </dl>
            <div className="rounded-md border border-navy-100 bg-navy-50 px-3.5 py-2.5 text-[13px] text-navy-700">
              {nextActorLabel(c) ? <>Next action: <b>{nextActorLabel(c)}</b></> : 'Closed — no further action.'}
            </div>
            <div className="no-print">
              <CaseActions c={c} size="md" includeDestructive />
            </div>
          </div>
          <SlaCountdown c={c} />
        </CardBody>
        <div className="border-t border-line px-4 py-4 sm:px-5">
          <CaseStepper status={c.status} history={c.history} />
        </div>
      </Card>

      {c.status === 'rework' && lastQc && <Alert tone="danger" title="Returned by quality control">{lastQc.notes}</Alert>}
      {c.status === 'correction' && <Alert tone="warning" title="Correction requested">{[...c.history].reverse().find((h) => h.toStatus === 'correction')?.note}</Alert>}

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
        <div className="flex min-w-0 flex-col gap-4">
          {/* Case information */}
          <Card>
            <CardHeader title="Case information" />
            <CardBody className="flex flex-col gap-5">
              <dl className="grid grid-cols-2 gap-4 rounded-md border border-navy-100 bg-navy-50 p-3.5 sm:grid-cols-4">
                <Field label="Patient">{can(PERMISSIONS.PATIENTS_VIEW) ? <Link className="hover:underline" to={`/patients/${c.patient.id}`}>{c.patient.name}</Link> : c.patient.name}</Field>
                <Field label="Patient reference" mono>{c.patient.code}</Field>
                <Field label="Phone">{c.patient.phone || '—'}</Field>
                <Field label="Date of birth">{c.patient.dateOfBirth ? formatDate(c.patient.dateOfBirth) : '—'}</Field>
                <Field label="Doctor">{c.doctor.name}</Field>
                <Field label="Doctor phone">{c.doctor.phone}</Field>
                <Field label="Clinic">{c.clinic.name}</Field>
                <Field label="Clinic phone">{c.clinic.phone}</Field>
              </dl>
              <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Field label="Case type">{CASE_TYPE_LABELS[c.caseType]}</Field>
                <Field label="Restoration">{c.restorationType}</Field>
                <Field label="Material">{c.material}</Field>
                <Field label="Shade" mono>{c.shade}</Field>
                <Field label={c.teeth.length ? 'Teeth' : 'Type'} mono>
                  {c.teeth.length ? formatTeeth(c.teeth) : c.dentureType ? DENTURE_TYPES.find((d) => d.value === c.dentureType)?.label : 'Full arch'}
                </Field>
                <Field label="Submitted">{c.submittedAt ? formatDateTime(c.submittedAt) : '—'}</Field>
                <Field label="Due">{c.dueAt ? formatDateTime(c.dueAt) : 'Starts on acceptance'}</Field>
                <Field label="Reworks" mono>{c.reworkCount}</Field>
              </dl>
              <div className="flex flex-col gap-1.5">
                <span className="eyebrow">Prescription / instructions</span>
                <p className="text-[13.5px] leading-relaxed whitespace-pre-wrap text-ink">{c.instructions || 'No additional instructions.'}</p>
              </div>
              {c.teeth.length > 0 && (
                <details className="group rounded-md border border-line">
                  <summary className="cursor-pointer px-3.5 py-2.5 text-[13px] font-semibold text-ink-2 select-none">Show tooth chart</summary>
                  <div className="px-3 pb-3"><ToothChart value={c.teeth} readOnly /></div>
                </details>
              )}
            </CardBody>
          </Card>

          {/* Production */}
          <Card>
            <CardHeader title="Production" />
            <CardBody className="flex flex-col gap-4">
              <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Field label="Assigned technician">{c.technician ? (can(PERMISSIONS.TECHNICIANS_VIEW) ? <Link className="hover:underline" to={`/technicians/${c.technician.id}`}>{c.technician.name}</Link> : c.technician.name) : 'Not assigned'}</Field>
                <Field label="Current stage">{stageLabel(c.status)}</Field>
                <Field label="Started">{c.productionStartedAt ? formatDateTime(c.productionStartedAt) : '—'}</Field>
                <Field label="Completed">{c.productionCompletedAt ? formatDateTime(c.productionCompletedAt) : '—'}</Field>
                <Field label="Production time" mono>{prodMs !== null ? formatDuration(prodMs) : '—'}</Field>
              </dl>
              <ProductionNotes c={c} />
            </CardBody>
          </Card>

          {/* QC */}
          <Card>
            <CardHeader title="Quality control" />
            <CardBody>
              {c.qualityChecks.length === 0 ? (
                <p className="text-[13px] text-ink-3">{c.status === 'quality_control' ? 'Waiting for inspection.' : 'Not inspected yet.'}</p>
              ) : (
                <ul className="flex flex-col gap-2.5">
                  {[...c.qualityChecks].reverse().map((q) => (
                    <li key={q.id} className={`flex flex-col gap-1.5 rounded-md px-3.5 py-3 ${q.result === 'passed' ? 'bg-success-bg' : 'bg-danger-bg'}`}>
                      <div className="flex flex-wrap items-center gap-2">
                        {q.result === 'passed' ? <CheckCircle2 className="size-4 text-success" /> : <XCircle className="size-4 text-danger" />}
                        <span className={`text-sm font-bold ${q.result === 'passed' ? 'text-success' : 'text-danger'}`}>{q.result === 'passed' ? 'Passed' : 'Failed — rework required'}</span>
                        <span className="text-xs text-ink-2">by {q.checkedByName} · {formatDateTime(q.checkedAt)}</span>
                      </div>
                      {q.issues.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                          {q.issues.map((i) => <Badge key={i} tone="danger">{QC_ISSUE_LABELS[i]}</Badge>)}
                        </div>
                      )}
                      {q.notes && <p className="text-[13px] text-ink">{q.notes}</p>}
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>

          {/* Timeline */}
          <Card>
            <CardHeader title="Case timeline" description="Every status change, who made it and how long the previous step took." />
            <CardBody>
              <CaseHistoryList history={c.history} />
            </CardBody>
          </Card>
        </div>

        <div className="flex min-w-0 flex-col gap-4">
          {canMoney && c.invoice && (
            <Card>
              <CardHeader
                title="Payment"
                actions={<Link to={`/invoices/${c.invoice.id}`} className="font-mono text-xs font-bold text-navy-500 hover:underline">{c.invoice.invoiceNumber}</Link>}
              />
              <CardBody className="flex flex-col gap-3">
                <dl className="grid grid-cols-3 gap-3">
                  <Field label="Total" mono>{formatMoney(c.invoice.total)}</Field>
                  <Field label="Paid" mono>{formatMoney(c.invoice.paid)}</Field>
                  <Field label="Remaining" mono>{formatMoney(c.invoice.remaining)}</Field>
                </dl>
                <ProgressBar value={c.invoice.total ? c.invoice.paid / c.invoice.total : 0} tone={c.invoice.status === 'paid' ? 'success' : c.invoice.status === 'overdue' ? 'danger' : 'warning'} label="Paid share" />
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <PaymentBadge status={c.invoice.status} />
                  <span className="text-xs text-ink-3">Due {formatDate(c.invoice.dueDate)}</span>
                </div>
                {can(PERMISSIONS.PAYMENTS_RECORD) && c.invoice.remaining > 0 && (
                  <Button variant="secondary" onClick={() => setPayOpen(true)}>Record payment</Button>
                )}
              </CardBody>
            </Card>
          )}
          {c.status === 'submitted' && canMoney === false && can(PERMISSIONS.INVOICES_VIEW) && <Alert>An invoice is created when Reception accepts the case.</Alert>}

          <Card>
            <CardHeader title="Case files" description={`${c.attachmentCount} attachment${c.attachmentCount === 1 ? '' : 's'}`} />
            <CardBody>
              <CaseAttachments caseId={c.id} attachments={c.attachments} />
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Delivery" />
            <CardBody>
              {!delivery ? (
                <p className="text-[13px] text-ink-3">Delivery is recorded after quality control passes.</p>
              ) : (
                <dl className="grid grid-cols-2 gap-3">
                  <Field label="Status">{DELIVERY_STATUS_LABELS[delivery.status]}</Field>
                  <Field label="Method">{DELIVERY_METHOD_LABELS[delivery.method]}</Field>
                  <Field label="Delivered by">{delivery.recordedByName}</Field>
                  <Field label="Courier">{delivery.courierName || '—'}</Field>
                  <Field label="Dispatched">{delivery.dispatchedAt ? formatDateTime(delivery.dispatchedAt) : '—'}</Field>
                  <Field label="Delivered to">{delivery.deliveredTo || '—'}</Field>
                  <Field label="Received by">{delivery.receivedBy || '—'}</Field>
                  <Field label="Delivered at" className="col-span-2">{delivery.deliveredAt ? formatDateTime(delivery.deliveredAt) : '—'}</Field>
                  {delivery.notes && <Field label="Notes" className="col-span-2">{delivery.notes}</Field>}
                </dl>
              )}
            </CardBody>
          </Card>
        </div>
      </div>

      {c.invoice && <RecordPaymentDialog open={payOpen} onOpenChange={setPayOpen} invoiceId={c.invoice.id} invoiceNumber={c.invoice.invoiceNumber} remaining={c.invoice.remaining} />}
      {can(PERMISSIONS.CASES_EDIT) && <EditCaseDialog c={c} open={editOpen} onOpenChange={setEditOpen} />}
    </div>
  );
}

function ProductionNotes({ c }: { c: CaseDetail }) {
  const { can } = useAuth();
  const [text, setText] = useState('');
  const add = useAddCaseNote(c.id);
  const canNote = can([PERMISSIONS.CASES_EDIT, PERMISSIONS.CASES_UPDATE_STATUS, PERMISSIONS.QC_PERFORM, PERMISSIONS.CASES_ASSIGN], 'any') && STATUS_META[c.status].open;

  return (
    <div className="flex flex-col gap-2.5">
      <span className="eyebrow">Production notes</span>
      {c.notes.length === 0 && <p className="text-[13px] text-ink-3">No notes yet.</p>}
      <ul className="flex flex-col gap-2">
        {c.notes.map((n) => (
          <li key={n.id} className="rounded-md bg-gray-50 px-3 py-2.5 text-[13.5px] leading-snug">
            {n.text}
            <span className="mt-1 block font-mono text-[11px] text-ink-3">{n.authorName} · {formatDateTime(n.createdAt)}</span>
          </li>
        ))}
      </ul>
      {canNote && (
        <form
          className="no-print flex flex-col gap-2 sm:flex-row sm:items-end"
          onSubmit={async (e) => {
            e.preventDefault();
            if (!text.trim()) return;
            try {
              await add.mutateAsync(text.trim());
              setText('');
              toast.success('Note added');
            } catch (err) {
              toast.error(errorMessage(err));
            }
          }}
        >
          <label className="flex-1">
            <span className="sr-only">New production note</span>
            <Textarea value={text} onChange={(e) => setText(e.target.value)} rows={2} placeholder="Zirconia milling completed. Starting sintering." maxLength={1000} />
          </label>
          <Button type="submit" variant="secondary" loading={add.isPending} disabled={!text.trim()}>Add note</Button>
        </form>
      )}
    </div>
  );
}
