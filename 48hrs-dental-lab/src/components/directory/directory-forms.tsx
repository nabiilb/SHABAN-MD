import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { emailField, optionalEmail, optionalPhone, optionalText, phoneField, requiredText } from '@/lib/validation';
import { useClinics, useSaveClinic, useSaveDoctor, useSavePatient, useSaveTechnician } from '@/hooks/api/use-directory';
import type { Clinic, Doctor, Patient, Technician } from '@/types/models';
import { applyApiErrors } from '@/components/forms/api-errors';
import { SelectField, SwitchField, TextField, TextareaField } from '@/components/forms/fields';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';

interface DialogProps<T> {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  record?: T | null;
  onSaved?: (r: T) => void;
}

function Footer({ onCancel, onSave, loading, label }: { onCancel: () => void; onSave: () => void; loading: boolean; label: string }) {
  return (
    <>
      <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      <Button onClick={onSave} loading={loading}>{label}</Button>
    </>
  );
}

/* ------------------------------- Patient -------------------------------- */

const patientSchema = z.object({
  name: requiredText('Patient name'),
  code: z.string().trim().max(40).optional(),
  phone: optionalPhone,
  email: optionalEmail,
  gender: z.enum(['', 'male', 'female']).optional(),
  dateOfBirth: z.string().optional().refine((v) => !v || new Date(v).getTime() < Date.now(), 'Date of birth cannot be in the future.'),
  clinicId: z.string().optional(),
  notes: optionalText(1000),
});
type PatientValues = z.infer<typeof patientSchema>;

export function PatientFormDialog({ open, onOpenChange, record, onSaved }: DialogProps<Patient>) {
  const save = useSavePatient();
  const clinics = useClinics({ perPage: 200 }, open);
  const { register, handleSubmit, reset, setError, formState: { errors } } = useForm<PatientValues>({ resolver: zodResolver(patientSchema) });
  useEffect(() => {
    if (open) reset({ name: record?.name ?? '', code: record?.code ?? '', phone: record?.phone ?? '', email: record?.email ?? '', gender: record?.gender ?? '', dateOfBirth: record?.dateOfBirth ?? '', clinicId: record?.clinicId ?? '', notes: record?.notes ?? '' });
  }, [open, record, reset]);

  const onSubmit = async (v: PatientValues) => {
    try {
      const saved = await save.mutateAsync({ id: record?.id, payload: { ...v, gender: v.gender || null, dateOfBirth: v.dateOfBirth || null, clinicId: v.clinicId || null } });
      toast.success(record ? 'Patient updated' : `Patient ${saved.code} added`);
      onOpenChange(false);
      onSaved?.(saved);
    } catch (err) {
      const msg = applyApiErrors(err, setError, Object.keys(patientSchema.shape));
      if (msg) toast.error(msg);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent size="lg" title={record ? 'Edit patient' : 'Add patient'} meta={record?.code} footer={<Footer onCancel={() => onOpenChange(false)} onSave={handleSubmit(onSubmit)} loading={save.isPending} label="Save patient" />}>
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4 sm:grid-cols-2">
          <TextField name="name" label="Full name" register={register} errors={errors} required placeholder="Ahmed Mohamed" />
          <TextField name="code" label="Patient reference" register={register} errors={errors} optional hint="Generated when left empty." placeholder="PT-1024" />
          <TextField name="phone" label="Phone" type="tel" register={register} errors={errors} optional />
          <TextField name="email" label="Email" type="email" register={register} errors={errors} optional />
          <SelectField name="gender" label="Gender" register={register} errors={errors} optional options={[{ value: 'male', label: 'Male' }, { value: 'female', label: 'Female' }]} placeholder="Not specified" />
          <TextField name="dateOfBirth" label="Date of birth" type="date" register={register} errors={errors} optional />
          <SelectField name="clinicId" label="Clinic" register={register} errors={errors} optional className="sm:col-span-2" options={(clinics.data?.data ?? []).map((k) => ({ value: k.id, label: k.name }))} placeholder="No clinic" />
          <TextareaField name="notes" label="Notes" register={register} errors={errors} optional rows={3} className="sm:col-span-2" />
        </form>
      </DialogContent>
    </Dialog>
  );
}

/* -------------------------------- Doctor -------------------------------- */

const doctorSchema = z.object({
  name: requiredText('Name'),
  clinicId: z.string().min(1, 'Select the clinic.'),
  phone: phoneField,
  email: optionalEmail,
  specialty: optionalText(80),
  active: z.boolean(),
});
type DoctorValues = z.infer<typeof doctorSchema>;

export function DoctorFormDialog({ open, onOpenChange, record, defaultClinicId }: DialogProps<Doctor> & { defaultClinicId?: string }) {
  const save = useSaveDoctor();
  const clinics = useClinics({ perPage: 200 }, open);
  const { register, control, handleSubmit, reset, setError, formState: { errors } } = useForm<DoctorValues>({ resolver: zodResolver(doctorSchema) });
  useEffect(() => {
    if (open) reset({ name: record?.name ?? 'Dr. ', clinicId: record?.clinicId ?? defaultClinicId ?? '', phone: record?.phone ?? '', email: record?.email ?? '', specialty: record?.specialty ?? '', active: record ? record.status === 'active' : true });
  }, [open, record, defaultClinicId, reset]);

  const onSubmit = async (v: DoctorValues) => {
    try {
      await save.mutateAsync({ id: record?.id, payload: { name: v.name, clinicId: v.clinicId, phone: v.phone, email: v.email ?? '', specialty: v.specialty ?? '', status: v.active ? 'active' : 'inactive' } });
      toast.success(record ? 'Doctor updated' : 'Doctor added');
      onOpenChange(false);
    } catch (err) {
      const msg = applyApiErrors(err, setError, ['name', 'clinicId', 'phone', 'email', 'specialty']);
      if (msg) toast.error(msg);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent size="lg" title={record ? 'Edit doctor' : 'Add doctor'} footer={<Footer onCancel={() => onOpenChange(false)} onSave={handleSubmit(onSubmit)} loading={save.isPending} label="Save doctor" />}>
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4 sm:grid-cols-2">
          <TextField name="name" label="Full name" register={register} errors={errors} required />
          <SelectField name="clinicId" label="Clinic" register={register} errors={errors} required options={(clinics.data?.data ?? []).map((k) => ({ value: k.id, label: k.name }))} placeholder="Select clinic" />
          <TextField name="phone" label="Phone" type="tel" register={register} errors={errors} required />
          <TextField name="email" label="Email" type="email" register={register} errors={errors} optional />
          <TextField name="specialty" label="Specialty" register={register} errors={errors} optional placeholder="Prosthodontics" className="sm:col-span-2" />
          <div className="sm:col-span-2"><SwitchField name="active" control={control} label="Active" description="Inactive doctors cannot be chosen for new cases." /></div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/* -------------------------------- Clinic -------------------------------- */

const clinicSchema = z.object({
  name: requiredText('Clinic name'),
  contactPerson: optionalText(120),
  phone: phoneField,
  email: optionalEmail,
  address: optionalText(240),
  active: z.boolean(),
  notes: optionalText(1000),
});
type ClinicValues = z.infer<typeof clinicSchema>;

export function ClinicFormDialog({ open, onOpenChange, record }: DialogProps<Clinic>) {
  const save = useSaveClinic();
  const { register, control, handleSubmit, reset, setError, formState: { errors } } = useForm<ClinicValues>({ resolver: zodResolver(clinicSchema) });
  useEffect(() => {
    if (open) reset({ name: record?.name ?? '', contactPerson: record?.contactPerson ?? '', phone: record?.phone ?? '', email: record?.email ?? '', address: record?.address ?? '', active: record ? record.status === 'active' : true, notes: record?.notes ?? '' });
  }, [open, record, reset]);

  const onSubmit = async (v: ClinicValues) => {
    try {
      await save.mutateAsync({ id: record?.id, payload: { name: v.name, contactPerson: v.contactPerson ?? '', phone: v.phone, email: v.email ?? '', address: v.address ?? '', status: v.active ? 'active' : 'inactive', notes: v.notes ?? '' } });
      toast.success(record ? 'Clinic updated' : 'Clinic added');
      onOpenChange(false);
    } catch (err) {
      const msg = applyApiErrors(err, setError, ['name', 'contactPerson', 'phone', 'email', 'address', 'notes']);
      if (msg) toast.error(msg);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent size="lg" title={record ? 'Edit clinic' : 'Add clinic'} footer={<Footer onCancel={() => onOpenChange(false)} onSave={handleSubmit(onSubmit)} loading={save.isPending} label="Save clinic" />}>
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4 sm:grid-cols-2">
          <TextField name="name" label="Clinic name" register={register} errors={errors} required />
          <TextField name="contactPerson" label="Contact person" register={register} errors={errors} optional />
          <TextField name="phone" label="Phone" type="tel" register={register} errors={errors} required />
          <TextField name="email" label="Email" type="email" register={register} errors={errors} optional />
          <TextField name="address" label="Address" register={register} errors={errors} optional className="sm:col-span-2" />
          <TextareaField name="notes" label="Notes" register={register} errors={errors} optional rows={3} className="sm:col-span-2" />
          <div className="sm:col-span-2"><SwitchField name="active" control={control} label="Active" description="Inactive clinics cannot submit or receive new cases." /></div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/* ------------------------------ Technician ------------------------------ */

const techSchema = z.object({
  name: requiredText('Name'),
  email: emailField,
  phone: phoneField,
  specialty: requiredText('Specialty', 80),
  active: z.boolean(),
});
type TechValues = z.infer<typeof techSchema>;

export function TechnicianFormDialog({ open, onOpenChange, record }: DialogProps<Technician>) {
  const save = useSaveTechnician();
  const { register, control, handleSubmit, reset, setError, formState: { errors } } = useForm<TechValues>({ resolver: zodResolver(techSchema) });
  useEffect(() => {
    if (open) reset({ name: record?.name ?? '', email: record?.email ?? '', phone: record?.phone ?? '', specialty: record?.specialty ?? '', active: record?.active ?? true });
  }, [open, record, reset]);

  const onSubmit = async (v: TechValues) => {
    try {
      await save.mutateAsync({ id: record?.id, payload: v });
      toast.success(record ? 'Technician updated' : 'Technician added');
      onOpenChange(false);
    } catch (err) {
      const msg = applyApiErrors(err, setError, ['name', 'email', 'phone', 'specialty']);
      if (msg) toast.error(msg);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent size="lg" title={record ? 'Edit technician' : 'Add technician'} description="To let them sign in, also create a user with the Technician role and the same email." footer={<Footer onCancel={() => onOpenChange(false)} onSave={handleSubmit(onSubmit)} loading={save.isPending} label="Save technician" />}>
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4 sm:grid-cols-2">
          <TextField name="name" label="Full name" register={register} errors={errors} required />
          <TextField name="specialty" label="Specialty" register={register} errors={errors} required placeholder="Crown & bridge" />
          <TextField name="email" label="Email" type="email" register={register} errors={errors} required />
          <TextField name="phone" label="Phone" type="tel" register={register} errors={errors} required />
          <div className="sm:col-span-2"><SwitchField name="active" control={control} label="Active" description="Inactive technicians cannot receive new assignments." /></div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
