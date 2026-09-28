import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import { cn } from '@/lib/cn';
import { DENTURE_TYPES, priceCase } from '@/lib/billing';
import { MATERIALS, SHADES } from '@/lib/constants';
import { PERMISSIONS } from '@/lib/permissions';
import { computeDueAt } from '@/lib/sla';
import { useAuth } from '@/hooks/use-auth';
import { useCreateCase } from '@/hooks/api/use-cases';
import { useClinics, useDoctors, usePatients } from '@/hooks/api/use-directory';
import { useServices, useSettings } from '@/hooks/api/use-admin';
import { useDebouncedValue } from '@/hooks/use-debounce';
import { usePageTitle } from '@/hooks/use-page-title';
import { useUnsavedChangesGuard } from '@/hooks/use-unsaved-changes';
import type { CasePriority, DentureType, LabService } from '@/types/models';
import { formatDateTime, formatMoney, formatTeeth } from '@/utils/format';
import { applyApiErrors } from '@/components/forms/api-errors';
import { Combobox } from '@/components/forms/combobox';
import { FormField } from '@/components/forms/form-field';
import { ToothChart } from '@/components/cases/tooth-chart';
import { FileDropzone } from '@/components/files/file-dropzone';
import { FilePreviewDialog, type PreviewTarget } from '@/components/files/file-preview-dialog';
import { UploadQueueList } from '@/components/files/upload-queue-list';
import { useUploadQueue } from '@/components/files/use-upload-queue';
import { Button } from '@/components/ui/button';
import { Card, CardBody, CardHeader, Field } from '@/components/ui/card';
import { Alert, ErrorState, PageLoader } from '@/components/ui/feedback';
import { Input, NativeSelect, Textarea } from '@/components/ui/input';

const schema = z
  .object({
    clinicId: z.string().min(1, 'Select the clinic.'),
    doctorId: z.string().min(1, 'Select the doctor.'),
    patientMode: z.enum(['existing', 'new']),
    patientId: z.string().optional(),
    newPatientName: z.string().trim().max(120).optional(),
    newPatientCode: z.string().trim().max(40).optional(),
    newPatientPhone: z.string().trim().max(40).optional(),
    serviceId: z.string().min(1, 'Select a service.'),
    unitMode: z.enum(['tooth', 'denture', 'arch']),
    teeth: z.array(z.number().int().min(1).max(32)),
    dentureType: z.string().optional(),
    shade: z.string().trim().min(1, 'Select a shade.'),
    material: z.string().trim().min(1, 'Select a material.').max(120),
    priority: z.enum(['normal', 'high', 'urgent']),
    dueAt: z.string().optional(),
    instructions: z.string().trim().max(2000, 'Keep instructions under 2000 characters.'),
  })
  .superRefine((v, ctx) => {
    if (v.patientMode === 'existing' && !v.patientId) ctx.addIssue({ code: 'custom', path: ['patientId'], message: 'Select the patient, or add a new patient.' });
    if (v.patientMode === 'new' && !v.newPatientName) ctx.addIssue({ code: 'custom', path: ['newPatientName'], message: 'Enter the patient name before submitting.' });
    if (v.unitMode === 'tooth' && v.teeth.length === 0) ctx.addIssue({ code: 'custom', path: ['teeth'], message: 'Select at least one tooth on the chart before submitting.' });
    if (v.unitMode === 'denture' && !v.dentureType) ctx.addIssue({ code: 'custom', path: ['dentureType'], message: 'Select the denture type.' });
    if (v.dueAt && new Date(v.dueAt).getTime() <= Date.now()) ctx.addIssue({ code: 'custom', path: ['dueAt'], message: 'The due date must be in the future.' });
  });
type Values = z.infer<typeof schema>;

const API_FIELDS = ['clinicId', 'doctorId', 'patientId', 'serviceId', 'teeth', 'dentureType', 'shade', 'material', 'priority', 'dueAt', 'instructions'] as const;

export default function NewCasePage() {
  const { user, can } = useAuth();
  const isStaff = can(PERMISSIONS.CASES_CREATE);
  usePageTitle('New Case', isStaff ? 'Register a case — the 48-hour clock starts on save' : 'Submit a case to the lab');
  const navigate = useNavigate();
  const services = useServices();
  const settings = useSettings();
  const clinics = useClinics({ perPage: 200, status: 'active' });
  const create = useCreateCase();
  const queue = useUploadQueue();
  const [preview, setPreview] = useState<PreviewTarget | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const { control, register, handleSubmit, setValue, setError, formState: { errors, isSubmitting, isDirty } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      clinicId: user?.clinicId ?? '',
      doctorId: user?.doctorId ?? '',
      patientMode: 'existing',
      serviceId: '',
      unitMode: 'tooth',
      teeth: [],
      dentureType: 'full_upper',
      shade: 'A2',
      material: '',
      priority: 'normal',
      dueAt: '',
      instructions: '',
    },
  });

  const v = useWatch({ control });
  const guard = useUnsavedChangesGuard(isDirty || queue.items.length > 0);
  const clinicId = v.clinicId ?? '';
  const doctors = useDoctors({ perPage: 200, status: 'active', clinicId: clinicId || undefined }, !!clinicId);
  const [patientSearch, setPatientSearch] = useState('');
  const debouncedPatient = useDebouncedValue(patientSearch, 250);
  const patients = usePatients({ perPage: 20, search: debouncedPatient || undefined, clinicId: clinicId || undefined }, !!clinicId && v.patientMode === 'existing');

  const service = services.data?.find((s) => s.id === v.serviceId);

  // Default to the first service; material follows the chosen service.
  useEffect(() => {
    if (!v.serviceId && services.data?.length) pickService(services.data[0]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [services.data]);

  function pickService(s: LabService) {
    setValue('serviceId', s.id, { shouldValidate: false });
    setValue('unitMode', s.unitMode);
    setValue('material', s.defaultMaterial);
    if (s.unitMode === 'arch') setValue('shade', 'Clear');
    else if (v.shade === 'Clear') setValue('shade', 'A2');
  }

  const price = useMemo(
    () => (service && settings.data ? priceCase(service, v.teeth ?? [], v.dentureType as DentureType, v.priority === 'urgent', settings.data.emergencyFeePerUnit) : null),
    [service, settings.data, v.teeth, v.dentureType, v.priority],
  );

  const onSubmit = async (values: Values) => {
    setFormError(null);
    try {
      const created = await create.mutateAsync({
        clinicId: values.clinicId,
        doctorId: values.doctorId,
        patientId: values.patientMode === 'existing' ? values.patientId : null,
        newPatient: values.patientMode === 'new' ? { name: values.newPatientName!, code: values.newPatientCode, phone: values.newPatientPhone } : null,
        serviceId: values.serviceId,
        material: values.material,
        shade: values.shade,
        teeth: values.unitMode === 'tooth' ? values.teeth : [],
        dentureType: values.unitMode === 'denture' ? (values.dentureType as DentureType) : null,
        priority: values.priority as CasePriority,
        instructions: values.instructions,
        receiveNow: isStaff,
        dueAt: values.dueAt ? new Date(values.dueAt).toISOString() : null,
      });
      const failed = queue.pending ? await queue.uploadAll(created.id) : 0;
      toast.success(isStaff ? `Case ${created.caseNumber} registered — 48-hour clock started` : `Case ${created.caseNumber} submitted — waiting for reception`);
      if (failed) toast.warning(`${failed} file${failed === 1 ? '' : 's'} failed to upload. Retry from the case page.`);
      guard.allowNavigation();
      navigate(`/cases/${created.id}`, { replace: true });
    } catch (err) {
      setFormError(applyApiErrors(err, setError, API_FIELDS));
    }
  };

  const onInvalid = () => setFormError('Some fields need attention — check the highlighted sections.');

  if (services.isLoading || settings.isLoading) return <PageLoader />;
  if (services.error || settings.error) return <ErrorState message="The service list could not be loaded." onRetry={() => void services.refetch()} />;
  const receivedPreview = settings.data ? computeDueAt(new Date(), settings.data.slaHours) : null;
  const submitting = isSubmitting || create.isPending || queue.busy;

  return (
    <form onSubmit={handleSubmit(onSubmit, onInvalid)} noValidate className="mx-auto flex w-full max-w-[1100px] flex-col gap-4 pb-24 md:pb-0">
      {/* Patient information */}
      <Card>
        <CardHeader title="Patient information" />
        <CardBody className="flex flex-col gap-4">
          <div className="grid gap-4 sm:grid-cols-2">
            {isStaff ? (
              <FormField label="Clinic" required error={errors.clinicId?.message}>
                {(a) => (
                  <Controller
                    control={control}
                    name="clinicId"
                    render={({ field }) => (
                      <Combobox
                        {...a}
                        loading={clinics.isLoading}
                        value={field.value}
                        onChange={(val) => {
                          field.onChange(val ?? '');
                          setValue('doctorId', '');
                          setValue('patientId', undefined);
                        }}
                        placeholder="Select clinic"
                        options={(clinics.data?.data ?? []).map((k) => ({ value: k.id, label: k.name, description: `${k.contactPerson} · ${k.phone}` }))}
                      />
                    )}
                  />
                )}
              </FormField>
            ) : (
              <dl><Field label="Clinic">{clinics.data?.data.find((k) => k.id === user?.clinicId)?.name ?? '—'}</Field></dl>
            )}
            <FormField label="Dentist" required error={errors.doctorId?.message}>
              {(a) => (
                <Controller
                  control={control}
                  name="doctorId"
                  render={({ field }) => (
                    <Combobox
                      {...a}
                      disabled={!clinicId}
                      loading={doctors.isLoading}
                      value={field.value}
                      onChange={(val) => field.onChange(val ?? '')}
                      placeholder={clinicId ? 'Select dentist' : 'Select the clinic first'}
                      options={(doctors.data?.data ?? []).map((d) => ({ value: d.id, label: d.name, description: d.specialty }))}
                    />
                  )}
                />
              )}
            </FormField>
          </div>

          <div className="flex gap-2" role="radiogroup" aria-label="Patient">
            {(['existing', 'new'] as const).map((m) => (
              <button
                key={m}
                type="button"
                role="radio"
                aria-checked={v.patientMode === m}
                onClick={() => setValue('patientMode', m)}
                className={cn('rounded-full border px-3.5 py-1.5 text-[13px] font-semibold', v.patientMode === m ? 'border-brand bg-navy-50 text-brand' : 'border-line-strong text-ink-2')}
              >
                {m === 'existing' ? 'Existing patient' : 'New patient'}
              </button>
            ))}
          </div>

          {v.patientMode === 'existing' ? (
            <FormField label="Patient" required error={errors.patientId?.message} hint={!clinicId ? 'Select the clinic first.' : undefined}>
              {(a) => (
                <Controller
                  control={control}
                  name="patientId"
                  render={({ field }) => (
                    <Combobox
                      {...a}
                      disabled={!clinicId}
                      value={field.value}
                      onChange={(val) => field.onChange(val ?? undefined)}
                      onSearchChange={setPatientSearch}
                      loading={patients.isFetching}
                      placeholder="Search by name, reference or phone"
                      emptyText="No patient found — switch to “New patient”."
                      options={(patients.data?.data ?? []).map((p) => ({ value: p.id, label: p.name, description: `${p.code}${p.phone ? ` · ${p.phone}` : ''}` }))}
                    />
                  )}
                />
              )}
            </FormField>
          ) : (
            <div className="grid gap-4 sm:grid-cols-3">
              <FormField label="Patient name" required error={errors.newPatientName?.message}>
                {(a) => <Input id={a.id} aria-invalid={a.invalid} aria-describedby={a.describedBy} placeholder="Ahmed Mohamed" {...register('newPatientName')} />}
              </FormField>
              <FormField label="Patient reference" optional hint="Leave empty to generate one.">
                {(a) => <Input id={a.id} aria-describedby={a.describedBy} placeholder="PT-1024" {...register('newPatientCode')} />}
              </FormField>
              <FormField label="Phone" optional>
                {(a) => <Input id={a.id} type="tel" placeholder="+252 61 …" {...register('newPatientPhone')} />}
              </FormField>
            </div>
          )}

          <dl className="grid grid-cols-2 gap-3 border-t border-line pt-4 sm:grid-cols-3">
            <Field label="Case reference" mono>Assigned on save</Field>
            <Field label="SLA">{isStaff ? 'Starts on save' : 'Starts on acceptance'}</Field>
            {isStaff && receivedPreview && <Field label="Due if saved now">{formatDateTime(receivedPreview)}</Field>}
          </dl>
        </CardBody>
      </Card>

      {/* Service */}
      <Card>
        <CardHeader title="Select service" description="Units and price follow your tooth selection." />
        <CardBody>
          <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-[repeat(auto-fill,minmax(180px,1fr))]" role="radiogroup" aria-label="Service">
            {services.data?.map((s) => {
              const on = s.id === v.serviceId;
              return (
                <button
                  key={s.id}
                  type="button"
                  role="radio"
                  aria-checked={on}
                  onClick={() => pickService(s)}
                  className={cn('flex flex-col gap-1.5 rounded-md px-3.5 py-3 text-left transition-colors hover:border-brand', on ? 'border-2 border-brand bg-navy-50' : 'border border-line')}
                >
                  <span className="text-[13.5px] leading-tight font-bold text-ink">{s.name}</span>
                  <span className="font-mono text-sm font-bold text-brand">
                    {formatMoney(s.unitPrice)} / {s.unitMode === 'tooth' ? 'tooth' : 'unit'}
                  </span>
                </button>
              );
            })}
          </div>
        </CardBody>
      </Card>

      {/* Teeth / denture / appliance */}
      {v.unitMode === 'tooth' && (
        <Card className={errors.teeth ? 'border-danger' : undefined}>
          <CardHeader title="Select teeth" description="Universal numbering 1–32 · tap a tooth to add or remove it" />
          <CardBody className="flex flex-col gap-3">
            <Controller control={control} name="teeth" render={({ field }) => <ToothChart value={field.value} onChange={field.onChange} invalid={!!errors.teeth} />} />
            {errors.teeth && <p className="text-xs font-semibold text-danger" role="alert">{errors.teeth.message}</p>}
          </CardBody>
        </Card>
      )}
      {v.unitMode === 'denture' && (
        <Card>
          <CardHeader title="Denture type" description="No tooth-by-tooth selection needed for this service." />
          <CardBody>
            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4" role="radiogroup" aria-label="Denture type">
              {DENTURE_TYPES.map((d) => (
                <button key={d.value} type="button" role="radio" aria-checked={v.dentureType === d.value} onClick={() => setValue('dentureType', d.value)} className={cn('flex flex-col gap-1 rounded-md px-3.5 py-3 text-left', v.dentureType === d.value ? 'border-2 border-brand bg-navy-50' : 'border border-line')}>
                  <span className="text-[13.5px] font-bold">{d.label}</span>
                  <span className="font-mono text-xs text-ink-2">{d.units === 1 ? '1 unit' : `${d.units} units`}</span>
                </button>
              ))}
            </div>
            {errors.dentureType && <p className="mt-2 text-xs font-semibold text-danger">{errors.dentureType.message}</p>}
          </CardBody>
        </Card>
      )}
      {v.unitMode === 'arch' && (
        <Card>
          <CardHeader title="Appliance" />
          <CardBody className="pt-2 text-[13.5px] text-ink-2">This service is billed as one full-arch unit — no tooth selection required.</CardBody>
        </Card>
      )}

      {/* Shade & material */}
      <Card>
        <CardHeader title="Shade & material" description="Shade applies to every selected tooth." />
        <CardBody className="flex flex-col gap-4">
          <div className="grid grid-cols-4 gap-2 sm:grid-cols-[repeat(auto-fit,minmax(64px,1fr))]" role="radiogroup" aria-label="Shade">
            {SHADES.map((sh) => (
              <button key={sh} type="button" role="radio" aria-checked={v.shade === sh} onClick={() => setValue('shade', sh, { shouldValidate: true })} className={cn('rounded-md border px-2 py-2.5 font-mono text-[13.5px] font-bold', v.shade === sh ? 'border-brand bg-brand text-white' : 'border-line-strong bg-card text-ink-2')}>
                {sh}
              </button>
            ))}
          </div>
          {errors.shade && <p className="text-xs font-semibold text-danger">{errors.shade.message}</p>}
          <FormField label="Material" required error={errors.material?.message} className="sm:max-w-sm">
            {(a) => (
              <NativeSelect id={a.id} aria-invalid={a.invalid} {...register('material')}>
                {[...new Set([...(service ? [service.defaultMaterial] : []), ...MATERIALS])].map((m) => <option key={m} value={m}>{m}</option>)}
              </NativeSelect>
            )}
          </FormField>
        </CardBody>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        {/* Files */}
        <Card>
          <CardHeader title="Case files" description="X-rays, photos, PDF prescriptions or STL scans for this case." />
          <CardBody className="flex flex-col gap-3">
            <FileDropzone onFiles={queue.add} compact />
            <span className="text-[12.5px] text-ink-2">Attached: {queue.items.length ? `${queue.items.length} file${queue.items.length === 1 ? '' : 's'}` : 'None yet'}</span>
            <UploadQueueList
              items={queue.items}
              onRemove={queue.remove}
              onRetry={queue.retry}
              onCategory={queue.setCategory}
              onPreview={(f) => setPreview({ name: f.file.name, url: f.previewUrl, kind: f.file.name.toLowerCase().endsWith('.pdf') ? 'pdf' : 'image' })}
            />
          </CardBody>
        </Card>

        {/* Priority & notes */}
        <Card>
          <CardHeader title="Priority & instructions" />
          <CardBody className="flex flex-col gap-4">
            <div className="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Priority">
              {([['normal', 'Normal'], ['high', 'High'], ['urgent', 'Emergency']] as const).map(([p, label]) => (
                <button key={p} type="button" role="radio" aria-checked={v.priority === p} onClick={() => setValue('priority', p)} className={cn('rounded-md border px-2 py-2.5 text-sm font-semibold', v.priority === p ? (p === 'urgent' ? 'border-2 border-danger bg-danger-bg text-danger' : 'border-2 border-brand bg-navy-50 text-brand') : 'border-line-strong text-ink-2')}>
                  {label}
                </button>
              ))}
            </div>
            {v.priority === 'urgent' && settings.data && <Alert tone="danger">Emergency turnaround adds {formatMoney(settings.data.emergencyFeePerUnit)} per unit and moves the case to the front of the queue.</Alert>}
            {isStaff && (
              <FormField label="Custom due date" optional hint={`Defaults to received time + ${settings.data?.slaHours ?? 48} hours.`} error={errors.dueAt?.message}>
                {(a) => <Input id={a.id} type="datetime-local" aria-invalid={a.invalid} aria-describedby={a.describedBy} {...register('dueAt')} />}
              </FormField>
            )}
            <FormField label="Prescription / instructions" optional error={errors.instructions?.message}>
              {(a) => <Textarea id={a.id} aria-invalid={a.invalid} rows={4} placeholder="Margin design, occlusion, contact preferences…" {...register('instructions')} />}
            </FormField>
          </CardBody>
        </Card>
      </div>

      {/* Summary */}
      <Card className="border-navy-100 shadow-md">
        <CardHeader title="Case summary" />
        <CardBody className="flex flex-col gap-4">
          <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <Field label="Service">{service?.name ?? '—'}</Field>
            <Field label={v.unitMode === 'tooth' ? 'Selected teeth' : 'Type'} mono>
              {v.unitMode === 'tooth' ? formatTeeth(v.teeth ?? []) : v.unitMode === 'denture' ? DENTURE_TYPES.find((d) => d.value === v.dentureType)?.label : 'Full arch'}
            </Field>
            <Field label="Units" mono>{price?.units ?? 0}</Field>
            <Field label="Shade" mono>{v.shade}</Field>
            <Field label="Unit price" mono>{formatMoney(service?.unitPrice ?? 0)}</Field>
            <Field label="Files">{queue.items.length ? `${queue.items.length} attachment${queue.items.length === 1 ? '' : 's'}` : 'None yet'}</Field>
            <Field label="Subtotal" mono>{formatMoney(price?.subtotal ?? 0)}</Field>
            {!!price?.emergencyFee && <Field label={<span className="text-danger">Emergency fee</span>} mono><span className="text-danger">+{formatMoney(price.emergencyFee)}</span></Field>}
          </dl>
          <div className="flex flex-wrap items-baseline justify-between gap-3 border-t border-line pt-4">
            <span className="text-[13px] font-extrabold tracking-[.12em] text-ink-2">TOTAL</span>
            <span className="font-mono text-[32px] leading-none font-bold text-brand sm:text-[38px]">{formatMoney(price?.total ?? 0)}</span>
          </div>
          <Alert>{isStaff ? 'The 48-hour countdown starts as soon as you register the case.' : 'The 48-hour countdown starts only when Reception accepts the case.'}</Alert>
          {formError && <Alert tone="danger">{formError}</Alert>}
          <div className="hidden gap-2 md:flex">
            <Button asChild variant="ghost" size="lg">
              <Link to="/cases">Cancel</Link>
            </Button>
            <Button type="submit" size="lg" loading={submitting} className="flex-1">
              {isStaff ? 'Register case' : 'Submit case'}
            </Button>
          </div>
        </CardBody>
      </Card>

      {/* Mobile sticky bar (prototype) */}
      <div className="fixed inset-x-0 bottom-0 z-30 flex items-center justify-between gap-3 border-t border-line bg-card px-4 py-3 shadow-lg md:hidden">
        <div className="flex flex-col">
          <span className="text-[11px] font-bold tracking-[.1em] text-ink-3">TOTAL</span>
          <span className="font-mono text-xl leading-none font-bold text-brand">{formatMoney(price?.total ?? 0)}</span>
        </div>
        <Button type="submit" loading={submitting}>{isStaff ? 'Register case' : 'Submit case'}</Button>
      </div>

      <FilePreviewDialog target={preview} onClose={() => setPreview(null)} />
      {guard.element}
    </form>
  );
}
