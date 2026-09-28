import { useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { cn } from '@/lib/cn';
import { DELIVERY_METHOD_LABELS, PAYMENT_METHOD_LABELS, QC_ISSUE_LABELS } from '@/lib/constants';
import { PERMISSIONS } from '@/lib/permissions';
import { CASE_ACTIONS, validateActionInput } from '@/lib/workflow';
import { paymentReferenceRequired } from '@/lib/billing';
import { useCaseAction } from '@/hooks/api/use-cases';
import { useTechnicians } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { errorMessage } from '@/services/api/errors';
import type { CaseActionKey, CaseActionPayload } from '@/types/api';
import type { CaseListItem, DeliveryMethod, PaymentMethod, QcIssue } from '@/types/models';
import { formatMoney, formatTeeth } from '@/utils/format';
import { applyApiErrors } from '@/components/forms/api-errors';
import { FormField } from '@/components/forms/form-field';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Alert, Skeleton } from '@/components/ui/feedback';
import { Input, NativeSelect, Textarea } from '@/components/ui/input';
import { Field } from '@/components/ui/card';
import { StatusBadge } from './badges';

/** Dialog kinds; qc covers both qc_pass and qc_fail like the prototype's "Review QC" modal. */
export type ActionDialogKind = Exclude<CaseActionKey, 'qc_pass' | 'qc_fail'> | 'qc';

const schema = z.object({
  note: z.string().max(1000, 'Keep it under 1000 characters.').optional(),
  technicianId: z.string().optional(),
  payMode: z.enum(['none', 'full', 'partial']).optional(),
  payAmount: z.coerce.number().optional(),
  payMethod: z.string().optional(),
  payReference: z.string().optional(),
  issues: z.array(z.string()).optional(),
  deliveryMethod: z.string().optional(),
  courierName: z.string().optional(),
  deliveredTo: z.string().optional(),
  receivedBy: z.string().optional(),
});
type Values = z.infer<typeof schema>;

/** API payload paths (422 keys) -> form fields. */
const API_TO_FIELD: Record<string, keyof Values> = {
  note: 'note',
  technicianId: 'technicianId',
  'qc.issues': 'issues',
  'payment.amount': 'payAmount',
  'payment.reference': 'payReference',
  'delivery.method': 'deliveryMethod',
  'delivery.courierName': 'courierName',
  'delivery.receivedBy': 'receivedBy',
};

const TITLES: Record<ActionDialogKind, string> = {
  accept: 'Accept case',
  request_correction: 'Request correction',
  resubmit: 'Resubmit case',
  reject: 'Reject case',
  start_review: 'Start review',
  assign: 'Assign technician',
  start_production: 'Start production',
  submit_qc: 'Submit for quality control',
  qc: 'Quality control review',
  start_rework: 'Start rework',
  dispatch: 'Dispatch case',
  deliver: 'Deliver case',
  confirm_receipt: 'Confirm received',
  cancel: 'Cancel case',
};

const DESCRIPTIONS: Partial<Record<ActionDialogKind, string>> = {
  request_correction: 'The case returns to the clinic for correction. The 48-hour timer does not start.',
  reject: 'The clinic is notified with your reason. Rejected cases cannot be reopened.',
  cancel: 'Work stops and the 48-hour clock is cleared. This cannot be undone.',
  assign: 'Choose a technician. Workload is live.',
  submit_qc: 'The case moves to the quality-control queue.',
  deliver: 'Record the hand-over. Delivery time closes the 48-hour window.',
  dispatch: 'The case leaves the lab with a courier.',
};

interface Props {
  kind: ActionDialogKind | null;
  c: CaseListItem;
  onClose: () => void;
  onDone?: () => void;
}

export function CaseActionDialog({ kind, c, onClose, onDone }: Props) {
  const { can } = useAuth();
  const mutation = useCaseAction();
  const techs = useTechnicians({ active: true, perPage: 100 }, kind === 'assign');
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: defaults(c) });
  const { register, handleSubmit, watch, setValue, setError, reset, formState } = form;

  useEffect(() => {
    if (kind) reset(defaults(c));
  }, [kind, c, reset]);

  const values = watch();
  const canTakePayment = can(PERMISSIONS.PAYMENTS_RECORD);
  const sortedTechs = useMemo(() => [...(techs.data?.data ?? [])].sort((a, b) => a.activeCases - b.activeCases), [techs.data]);

  const run = async (action: CaseActionKey, v: Values) => {
    const def = CASE_ACTIONS[action];
    const note = v.note?.trim() ?? '';
    const payload: CaseActionPayload = { action, note: note || undefined };
    if (action === 'assign') payload.technicianId = v.technicianId;
    if (action === 'accept' && canTakePayment && v.payMode && v.payMode !== 'none') {
      payload.payment = { amount: v.payMode === 'full' ? c.total : Number(v.payAmount), method: v.payMethod as PaymentMethod, reference: v.payReference?.trim() };
    }
    if (action === 'qc_pass' || action === 'qc_fail') {
      payload.qc = { issues: (v.issues ?? []) as QcIssue[], notes: note, reworkRequired: action === 'qc_fail' };
    }
    if (action === 'dispatch' || action === 'deliver') {
      payload.delivery = { method: v.deliveryMethod as DeliveryMethod, courierName: v.courierName, deliveredTo: v.deliveredTo, receivedBy: v.receivedBy, notes: note };
    }

    // Same rules the API enforces (lib/workflow.validateActionInput).
    // Only submitted cases are accepted and they have no invoice yet, so the cap is the case total.
    const invalid = validateActionInput(payload, { maxPayment: c.total });
    if (Object.keys(invalid).length) {
      Object.entries(invalid).forEach(([path, message]) => setError(API_TO_FIELD[path] ?? 'note', { message }));
      return;
    }
    try {
      await mutation.mutateAsync({ id: c.id, payload });
      toast.success(`${def.label} — ${c.caseNumber}`);
      onClose();
      onDone?.();
    } catch (err) {
      const msg = applyApiErrors(err, (path, e) => setError(API_TO_FIELD[path as string] ?? (path as keyof Values), e), Object.keys(API_TO_FIELD));
      if (msg) toast.error(msg);
    }
  };

  if (!kind) return null;
  const submit = (action: CaseActionKey) => handleSubmit((v) => run(action, v));
  const busy = mutation.isPending;
  const err = formState.errors;
  const primaryAction: CaseActionKey = kind === 'qc' ? 'qc_pass' : kind;
  const def = CASE_ACTIONS[primaryAction];

  return (
    <Dialog open onOpenChange={(o) => !o && !busy && onClose()}>
      <DialogContent
        title={TITLES[kind]}
        meta={<span>{c.caseNumber} · <StatusBadge status={c.status} /></span>}
        description={DESCRIPTIONS[kind]}
        size={kind === 'qc' || kind === 'assign' ? 'lg' : 'md'}
        footer={
          <>
            <Button variant="ghost" onClick={onClose} disabled={busy}>Cancel</Button>
            {kind === 'qc' ? (
              <>
                <Button variant="danger" onClick={submit('qc_fail')} loading={busy && mutation.variables?.payload.action === 'qc_fail'} disabled={busy}>Fail — send back for rework</Button>
                <Button onClick={submit('qc_pass')} loading={busy && mutation.variables?.payload.action === 'qc_pass'} disabled={busy}>Pass QC</Button>
              </>
            ) : (
              <Button variant={def.variant === 'danger' ? 'danger' : 'primary'} onClick={submit(primaryAction)} loading={busy}>
                {kind === 'accept' ? 'Confirm acceptance' : kind === 'request_correction' ? 'Send back to clinic' : def.label}
              </Button>
            )}
          </>
        }
      >
        <form onSubmit={(e) => e.preventDefault()} className="flex flex-col gap-4" noValidate>
          <dl className="grid grid-cols-2 gap-3 rounded-md bg-gray-50 p-3.5 sm:grid-cols-3">
            <Field label="Patient">{c.patient.name}</Field>
            <Field label="Clinic">{c.clinic.name}</Field>
            <Field label="Service">{c.restorationType} · {c.units} unit{c.units === 1 ? '' : 's'}</Field>
            {c.teeth.length > 0 && <Field label="Teeth" mono>{formatTeeth(c.teeth)}</Field>}
            <Field label="Shade">{c.shade}</Field>
            <Field label="Total" mono>{formatMoney(c.total)}</Field>
          </dl>

          {kind === 'accept' && (
            <>
              {canTakePayment && (
                <div className="flex flex-col gap-3">
                  <span className="text-xs font-bold text-ink-2">Payment</span>
                  <div className="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Payment at acceptance">
                    {(['full', 'partial', 'none'] as const).map((m) => (
                      <button
                        key={m}
                        type="button"
                        role="radio"
                        aria-checked={values.payMode === m}
                        onClick={() => setValue('payMode', m)}
                        className={cn('rounded-md border px-2 py-2.5 text-sm font-semibold', values.payMode === m ? 'border-2 border-brand bg-navy-50 text-brand' : 'border-line-strong text-ink-2')}
                      >
                        {m === 'full' ? 'Paid in full' : m === 'partial' ? 'Deposit' : 'Pay later'}
                      </button>
                    ))}
                  </div>
                  {values.payMode !== 'none' && (
                    <div className="grid gap-3 sm:grid-cols-3">
                      {values.payMode === 'partial' && (
                        <FormField label="Amount" error={err.payAmount?.message} required>
                          {(a) => <Input id={a.id} aria-invalid={a.invalid} aria-describedby={a.describedBy} type="number" step="0.01" min="0" {...register('payAmount')} />}
                        </FormField>
                      )}
                      <FormField label="Method" required>
                        {(a) => (
                          <NativeSelect id={a.id} {...register('payMethod')}>
                            {Object.entries(PAYMENT_METHOD_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                          </NativeSelect>
                        )}
                      </FormField>
                      {paymentReferenceRequired(values.payMethod) && (
                        <FormField label="Reference" error={err.payReference?.message} required>
                          {(a) => <Input id={a.id} aria-invalid={a.invalid} aria-describedby={a.describedBy} placeholder="TX123456" {...register('payReference')} />}
                        </FormField>
                      )}
                    </div>
                  )}
                </div>
              )}
              <Alert>On confirmation: invoice created, payment recorded, the 48-hour timer starts, and the case moves to the Lab Manager queue.</Alert>
            </>
          )}

          {kind === 'assign' && (
            <FormField label="Technician" error={err.technicianId?.message} required>
              {() =>
                techs.isLoading ? (
                  <div className="flex flex-col gap-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-16" />)}</div>
                ) : (
                  <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Technician">
                    {sortedTechs.map((t) => {
                      const on = values.technicianId === t.id;
                      return (
                        <button
                          key={t.id}
                          type="button"
                          role="radio"
                          aria-checked={on}
                          onClick={() => setValue('technicianId', t.id, { shouldValidate: false })}
                          className={cn('flex items-center gap-3 rounded-md p-3 text-left', on ? 'border-2 border-brand bg-navy-50' : 'border border-line hover:border-line-strong')}
                        >
                          <Avatar name={t.name} />
                          <span className="flex min-w-0 flex-col gap-0.5">
                            <span className="text-[15px] font-bold text-ink">{t.name}</span>
                            <span className="text-[12.5px] text-ink-2">{t.specialty}</span>
                            <span className={cn('text-xs font-semibold', t.overdue ? 'text-danger' : 'text-ink-3')}>
                              Active: {t.activeCases} case{t.activeCases === 1 ? '' : 's'}{t.overdue ? ` · ${t.overdue} overdue` : ''}
                            </span>
                          </span>
                        </button>
                      );
                    })}
                  </div>
                )
              }
            </FormField>
          )}

          {kind === 'qc' && (
            <FormField label="Issues found" error={err.issues?.message} hint="Leave empty when the case passes.">
              {() => (
                <div className="grid gap-2 sm:grid-cols-2">
                  {(Object.keys(QC_ISSUE_LABELS) as QcIssue[]).map((issue) => {
                    const checked = values.issues?.includes(issue) ?? false;
                    return (
                      <label key={issue} className={cn('flex cursor-pointer items-center gap-2.5 rounded-md border px-3 py-2.5 text-sm', checked ? 'border-danger bg-danger-bg/60' : 'border-line')}>
                        <Checkbox
                          checked={checked}
                          onCheckedChange={(v) => setValue('issues', v ? [...(values.issues ?? []), issue] : (values.issues ?? []).filter((x) => x !== issue))}
                        />
                        {QC_ISSUE_LABELS[issue]}
                      </label>
                    );
                  })}
                </div>
              )}
            </FormField>
          )}

          {(kind === 'dispatch' || kind === 'deliver') && (
            <div className="grid gap-3 sm:grid-cols-2">
              <FormField label="Delivery method" required>
                {(a) => (
                  <NativeSelect id={a.id} {...register('deliveryMethod')}>
                    {Object.entries(DELIVERY_METHOD_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                  </NativeSelect>
                )}
              </FormField>
              {(kind === 'dispatch' || values.deliveryMethod !== 'clinic_pickup') && (
                <FormField label="Courier" error={err.courierName?.message} required={kind === 'dispatch' && values.deliveryMethod !== 'clinic_pickup'} optional={kind === 'deliver'}>
                  {(a) => <Input id={a.id} aria-invalid={a.invalid} aria-describedby={a.describedBy} placeholder="Bashir Omar" {...register('courierName')} />}
                </FormField>
              )}
              {kind === 'deliver' && (
                <>
                  <FormField label="Delivered to" optional>
                    {(a) => <Input id={a.id} placeholder={c.clinic.name} {...register('deliveredTo')} />}
                  </FormField>
                  <FormField label="Received by" error={err.receivedBy?.message} required>
                    {(a) => <Input id={a.id} aria-invalid={a.invalid} aria-describedby={a.describedBy} placeholder="Name of the person who signed" {...register('receivedBy')} />}
                  </FormField>
                </>
              )}
            </div>
          )}

          {kind !== 'start_review' && kind !== 'start_production' && kind !== 'start_rework' && kind !== 'confirm_receipt' && (
            <FormField
              label={kind === 'qc' ? 'QC notes' : def.noteRequired ? 'Reason' : 'Note'}
              error={err.note?.message}
              required={def.noteRequired && kind !== 'qc'}
              optional={!def.noteRequired}
              hint={kind === 'qc' ? 'Required when failing — the technician sees this.' : undefined}
            >
              {(a) => (
                <Textarea
                  id={a.id}
                  aria-invalid={a.invalid}
                  aria-describedby={a.describedBy}
                  rows={3}
                  placeholder={
                    kind === 'request_correction'
                      ? 'Impression file unreadable. Please re-upload the STL.'
                      : kind === 'qc'
                        ? 'Shade mismatch. Please correct to A2.'
                        : kind === 'submit_qc'
                          ? 'Zirconia milling completed. Glazed and polished.'
                          : ''
                  }
                  {...register('note')}
                />
              )}
            </FormField>
          )}

          {kind === 'qc' && c.reworkCount > 0 && <Alert tone="warning">This case has already been reworked {c.reworkCount} time{c.reworkCount === 1 ? '' : 's'}.</Alert>}
          {mutation.isError && !formState.isDirty && <Alert tone="danger">{errorMessage(mutation.error)}</Alert>}
        </form>
      </DialogContent>
    </Dialog>
  );
}

function defaults(c: CaseListItem): Values {
  return {
    note: '',
    technicianId: c.technicianId ?? undefined,
    payMode: 'full',
    payAmount: undefined,
    payMethod: 'cash',
    payReference: '',
    issues: [],
    deliveryMethod: 'lab_courier',
    courierName: '',
    deliveredTo: '',
    receivedBy: '',
  };
}
