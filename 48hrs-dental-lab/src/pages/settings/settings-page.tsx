import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { env } from '@/config/env';
import { CASE_TYPE_LABELS } from '@/lib/constants';
import { PERMISSIONS } from '@/lib/permissions';
import { optionalEmail, optionalPhone, requiredText } from '@/lib/validation';
import { useDeleteService, useResetDemo, useSaveService, useServices, useSettings, useUpdateSettings } from '@/hooks/api/use-admin';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { errorMessage } from '@/services/api/errors';
import type { LabService, LabSettings } from '@/types/models';
import { formatMoney } from '@/utils/format';
import { applyApiErrors } from '@/components/forms/api-errors';
import { SelectField, SwitchField, TextField } from '@/components/forms/fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Alert, ErrorState, PageLoader } from '@/components/ui/feedback';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

const intIn = (label: string, min: number, max: number) =>
  z.coerce.number({ invalid_type_error: `${label} must be a number.` }).int(`${label} must be a whole number.`).min(min, `${label} must be at least ${min}.`).max(max, `${label} must be at most ${max}.`);

const settingsSchema = z
  .object({
    labName: requiredText('Lab name'),
    phone: optionalPhone,
    email: optionalEmail,
    address: z.string().trim().max(240).optional(),
    currency: z.string().trim().regex(/^[A-Z]{3}$/, 'Use a 3-letter ISO code, e.g. USD.'),
    slaHours: intIn('Turnaround', 4, 240),
    atRiskHours: intIn('At-risk threshold', 1, 72),
    criticalHours: intIn('Critical threshold', 1, 48),
    emergencyFeePerUnit: z.coerce.number().min(0, 'Cannot be negative.').max(1000),
    invoiceDueDays: intIn('Invoice terms', 0, 120),
  })
  .refine((v) => v.criticalHours < v.atRiskHours, { path: ['criticalHours'], message: 'Critical must be lower than the at-risk threshold.' })
  .refine((v) => v.atRiskHours < v.slaHours, { path: ['atRiskHours'], message: 'At-risk must be lower than the turnaround.' });
type SettingsValues = z.infer<typeof settingsSchema>;

function LabSettingsForm({ settings, readOnly }: { settings: LabSettings; readOnly: boolean }) {
  const update = useUpdateSettings();
  const { register, handleSubmit, reset, setError, formState: { errors, isDirty } } = useForm<SettingsValues>({ resolver: zodResolver(settingsSchema), defaultValues: settings });
  useEffect(() => reset(settings), [settings, reset]);

  const onSubmit = async (v: SettingsValues) => {
    try {
      await update.mutateAsync({ ...settings, ...v, phone: v.phone ?? '', email: v.email ?? '', address: v.address ?? '' });
      toast.success('Settings saved');
    } catch (err) {
      const msg = applyApiErrors(err, setError, Object.keys(settings));
      if (msg) toast.error(msg);
    }
  };

  return (
    <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
      <fieldset disabled={readOnly} className="contents">
        <Card>
          <CardHeader title="Lab profile" description="Shown on invoices and printouts." />
          <CardBody className="grid gap-4 sm:grid-cols-2">
            <TextField name="labName" label="Lab name" register={register} errors={errors} required />
            <TextField name="currency" label="Currency" register={register} errors={errors} required hint="ISO code — all amounts use it." />
            <TextField name="phone" label="Phone" register={register} errors={errors} optional />
            <TextField name="email" label="Email" type="email" register={register} errors={errors} optional />
            <TextField name="address" label="Address" register={register} errors={errors} optional className="sm:col-span-2" />
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="48-hour commitment" description="due_at = received_at + turnaround. Thresholds colour the countdown and trigger alerts." />
          <CardBody className="grid gap-4 sm:grid-cols-3">
            <TextField name="slaHours" label="Turnaround (hours)" type="number" register={register} errors={errors} required />
            <TextField name="atRiskHours" label="At risk when ≤ (hours)" type="number" register={register} errors={errors} required />
            <TextField name="criticalHours" label="Critical when ≤ (hours)" type="number" register={register} errors={errors} required />
            <Alert className="sm:col-span-3">Changes apply to cases received from now on. Existing cases keep the due date they were given.</Alert>
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Billing" />
          <CardBody className="grid gap-4 sm:grid-cols-2">
            <TextField name="emergencyFeePerUnit" label="Emergency fee per unit" type="number" step="0.01" register={register} errors={errors} required />
            <TextField name="invoiceDueDays" label="Invoice terms (days)" type="number" register={register} errors={errors} required />
          </CardBody>
        </Card>
      </fieldset>
      {!readOnly && (
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => reset(settings)} disabled={!isDirty}>Discard</Button>
          <Button type="submit" loading={update.isPending} disabled={!isDirty}>Save settings</Button>
        </div>
      )}
    </form>
  );
}

const serviceSchema = z.object({
  name: requiredText('Service name'),
  caseType: z.enum(['crown', 'bridge', 'veneer', 'implant', 'denture', 'appliance']),
  unitMode: z.enum(['tooth', 'denture', 'arch']),
  unitPrice: z.coerce.number({ invalid_type_error: 'Enter a price.' }).min(0, 'Cannot be negative.'),
  defaultMaterial: requiredText('Default material'),
  active: z.boolean(),
});
type ServiceValues = z.infer<typeof serviceSchema>;

function ServiceDialog({ open, onOpenChange, record }: { open: boolean; onOpenChange: (o: boolean) => void; record: LabService | null }) {
  const save = useSaveService();
  const { register, control, handleSubmit, reset, setError, formState: { errors } } = useForm<ServiceValues>({ resolver: zodResolver(serviceSchema) });
  useEffect(() => {
    if (open) reset(record ?? { name: '', caseType: 'crown', unitMode: 'tooth', unitPrice: 20, defaultMaterial: 'Zirconia', active: true });
  }, [open, record, reset]);
  const onSubmit = async (v: ServiceValues) => {
    try {
      await save.mutateAsync({ id: record?.id, payload: v });
      toast.success(record ? 'Service updated' : 'Service added');
      onOpenChange(false);
    } catch (err) {
      const msg = applyApiErrors(err, setError, Object.keys(serviceSchema.shape));
      if (msg) toast.error(msg);
    }
  };
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={record ? 'Edit service' : 'Add service'} description="Price edits apply to new cases only." footer={<><Button variant="ghost" onClick={() => onOpenChange(false)}>Cancel</Button><Button onClick={handleSubmit(onSubmit)} loading={save.isPending}>Save service</Button></>}>
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4 sm:grid-cols-2">
          <TextField name="name" label="Service name" register={register} errors={errors} required placeholder="Implant Crown" className="sm:col-span-2" />
          <SelectField name="caseType" label="Case type" register={register} errors={errors} required options={Object.entries(CASE_TYPE_LABELS).map(([value, label]) => ({ value, label }))} />
          <SelectField name="unitMode" label="Billed per" register={register} errors={errors} required options={[{ value: 'tooth', label: 'Tooth' }, { value: 'denture', label: 'Denture arch' }, { value: 'arch', label: 'Appliance (1 unit)' }]} />
          <TextField name="unitPrice" label="Unit price" type="number" step="0.01" register={register} errors={errors} required />
          <TextField name="defaultMaterial" label="Default material" register={register} errors={errors} required />
          <div className="sm:col-span-2"><SwitchField name="active" control={control} label="Available for new cases" /></div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function ServicesPanel() {
  const { can } = useAuth();
  const q = useServices(true);
  const del = useDeleteService();
  const [editing, setEditing] = useState<LabService | null | 'new'>(null);
  const [toDelete, setToDelete] = useState<LabService | null>(null);
  const manage = can(PERMISSIONS.SERVICES_MANAGE);

  if (q.isLoading) return <PageLoader />;
  if (q.error) return <ErrorState message={errorMessage(q.error)} onRetry={() => void q.refetch()} />;

  return (
    <Card>
      <CardHeader title="Services & pricing" description="The price list clients order from." actions={manage && <Button size="sm" onClick={() => setEditing('new')}><Plus /> Add service</Button>} />
      <CardBody className="pt-3">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[560px] text-sm">
            <thead>
              <tr className="border-b border-line text-left text-[11px] font-bold tracking-[.12em] text-ink-3 uppercase">
                <th className="py-2.5 pr-4">Service</th><th className="py-2.5 pr-4">Type</th><th className="py-2.5 pr-4">Unit price</th><th className="py-2.5 pr-4">Default material</th><th className="py-2.5 pr-4">Status</th>{manage && <th className="py-2.5 text-right"><span className="sr-only">Actions</span></th>}
              </tr>
            </thead>
            <tbody>
              {q.data?.map((s) => (
                <tr key={s.id} className="border-b border-line last:border-0">
                  <td className="py-3 pr-4 font-bold">{s.name}</td>
                  <td className="py-3 pr-4">{CASE_TYPE_LABELS[s.caseType]}</td>
                  <td className="py-3 pr-4 font-mono font-bold">{formatMoney(s.unitPrice)} / {s.unitMode === 'tooth' ? 'tooth' : 'unit'}</td>
                  <td className="py-3 pr-4 text-ink-2">{s.defaultMaterial}</td>
                  <td className="py-3 pr-4"><Badge tone={s.active ? 'success' : 'neutral'}>{s.active ? 'Active' : 'Inactive'}</Badge></td>
                  {manage && (
                    <td className="py-3 text-right whitespace-nowrap">
                      <Button variant="ghost" size="icon-sm" aria-label={`Edit ${s.name}`} onClick={() => setEditing(s)}><Pencil /></Button>
                      <Button variant="ghost" size="icon-sm" aria-label={`Delete ${s.name}`} onClick={() => setToDelete(s)}><Trash2 className="text-danger" /></Button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardBody>
      <ServiceDialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)} record={editing && editing !== 'new' ? editing : null} />
      <ConfirmDialog
        open={!!toDelete}
        onOpenChange={(o) => !o && setToDelete(null)}
        title="Delete service?"
        tone="danger"
        confirmLabel="Delete service"
        loading={del.isPending}
        description="Services used by existing cases cannot be deleted — deactivate them instead."
        onConfirm={async () => {
          if (!toDelete) return;
          try {
            await del.mutateAsync(toDelete.id);
            toast.success('Service deleted');
          } catch (err) {
            toast.error(errorMessage(err));
          }
          setToDelete(null);
        }}
      />
    </Card>
  );
}

function DemoDataPanel() {
  const reset = useResetDemo();
  const [confirm, setConfirm] = useState(false);
  return (
    <Card>
      <CardHeader title="Demo data" description="The app is running against the in-browser mock API (VITE_USE_MOCKS=true)." />
      <CardBody className="flex flex-col items-start gap-3">
        <p className="text-[13px] text-ink-2">Resetting restores the seeded clinics, cases, invoices and users. Timestamps are regenerated relative to now so the 48-hour board shows a realistic mix again.</p>
        <Button variant="danger-soft" onClick={() => setConfirm(true)}><RotateCcw /> Reset demo data</Button>
      </CardBody>
      <ConfirmDialog
        open={confirm}
        onOpenChange={setConfirm}
        title="Reset demo data?"
        tone="danger"
        confirmLabel="Reset"
        loading={reset.isPending}
        description="Every change you made in this browser is replaced by the seeded dataset."
        onConfirm={async () => {
          try {
            await reset.mutateAsync();
            toast.success('Demo data restored');
            setConfirm(false);
          } catch (err) {
            toast.error(errorMessage(err));
          }
        }}
      />
    </Card>
  );
}

export default function SettingsPage() {
  usePageTitle('Settings', 'Lab profile, 48-hour rules and the service price list');
  const { can } = useAuth();
  const q = useSettings();
  if (q.isLoading) return <PageLoader />;
  if (q.error || !q.data) return <ErrorState message={errorMessage(q.error)} onRetry={() => void q.refetch()} />;
  const readOnly = !can(PERMISSIONS.SETTINGS_MANAGE);

  return (
    <div className="mx-auto flex w-full max-w-4xl flex-col gap-4">
      {readOnly && <Alert tone="warning">You can view settings but not change them.</Alert>}
      <Tabs defaultValue="general">
        <TabsList label="Settings sections">
          <TabsTrigger value="general">General & SLA</TabsTrigger>
          <TabsTrigger value="services">Services & pricing</TabsTrigger>
          {env.useMocks && can(PERMISSIONS.SETTINGS_MANAGE) && <TabsTrigger value="demo">Demo data</TabsTrigger>}
        </TabsList>
        <TabsContent value="general" className="mt-4"><LabSettingsForm settings={q.data} readOnly={readOnly} /></TabsContent>
        <TabsContent value="services" className="mt-4"><ServicesPanel /></TabsContent>
        {env.useMocks && <TabsContent value="demo" className="mt-4"><DemoDataPanel /></TabsContent>}
      </Tabs>
    </div>
  );
}
