import { useEffect } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { MATERIALS, PRIORITY_META, SHADES } from '@/lib/constants';
import { useUpdateCase } from '@/hooks/api/use-cases';
import type { CaseDetail, CasePriority } from '@/types/models';
import { applyApiErrors } from '@/components/forms/api-errors';
import { SelectField, TextareaField } from '@/components/forms/fields';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { ToothChart } from './tooth-chart';

const schema = z.object({
  shade: z.string().min(1, 'Select a shade.'),
  material: z.string().min(1, 'Select a material.'),
  priority: z.enum(['normal', 'high', 'urgent']),
  instructions: z.string().trim().max(2000),
  teeth: z.array(z.number()),
});
type Values = z.infer<typeof schema>;

export function EditCaseDialog({ c, open, onOpenChange }: { c: CaseDetail; open: boolean; onOpenChange: (o: boolean) => void }) {
  const update = useUpdateCase(c.id);
  const toothMode = c.service.unitMode === 'tooth';
  const { register, control, handleSubmit, reset, setError, formState: { errors } } = useForm<Values>({ resolver: zodResolver(schema) });

  useEffect(() => {
    if (open) reset({ shade: c.shade, material: c.material, priority: c.priority, instructions: c.instructions, teeth: c.teeth });
  }, [open, c, reset]);

  const onSubmit = async (v: Values) => {
    if (toothMode && v.teeth.length === 0) return setError('teeth', { message: 'Select at least one tooth.' });
    try {
      await update.mutateAsync({ shade: v.shade, material: v.material, priority: v.priority as CasePriority, instructions: v.instructions, teeth: toothMode ? v.teeth : undefined });
      toast.success(`${c.caseNumber} updated`);
      onOpenChange(false);
    } catch (err) {
      const msg = applyApiErrors(err, setError, ['shade', 'material', 'priority', 'instructions', 'teeth']);
      if (msg) toast.error(msg);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        size="lg"
        title="Edit case details"
        meta={c.caseNumber}
        description="Price is recalculated when teeth or priority change."
        footer={
          <>
            <Button variant="ghost" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button onClick={handleSubmit(onSubmit)} loading={update.isPending}>Save changes</Button>
          </>
        }
      >
        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-3">
            <SelectField name="shade" label="Shade" register={register} errors={errors} required options={SHADES.map((s) => ({ value: s, label: s }))} />
            <SelectField name="material" label="Material" register={register} errors={errors} required options={[...new Set([c.material, ...MATERIALS])].map((m) => ({ value: m, label: m }))} />
            <SelectField name="priority" label="Priority" register={register} errors={errors} required options={Object.entries(PRIORITY_META).map(([value, m]) => ({ value, label: m.label }))} />
          </div>
          {toothMode && (
            <div className="flex flex-col gap-2">
              <span className="text-xs font-bold text-ink-2">Teeth</span>
              <Controller control={control} name="teeth" render={({ field }) => <ToothChart value={field.value ?? []} onChange={field.onChange} invalid={!!errors.teeth} />} />
              {errors.teeth && <p className="text-xs font-semibold text-danger">{errors.teeth.message}</p>}
            </div>
          )}
          <TextareaField name="instructions" label="Prescription / instructions" register={register} errors={errors} rows={4} />
        </form>
      </DialogContent>
    </Dialog>
  );
}
