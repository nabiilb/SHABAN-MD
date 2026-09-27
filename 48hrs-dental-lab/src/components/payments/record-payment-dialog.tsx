import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { round2 } from '@/lib/billing';
import { PAYMENT_METHOD_LABELS } from '@/lib/constants';
import { useRecordPayment } from '@/hooks/api/use-finance';
import type { PaymentMethod } from '@/types/models';
import { formatMoney } from '@/utils/format';
import { applyApiErrors } from '@/components/forms/api-errors';
import { SelectField, TextField, TextareaField } from '@/components/forms/fields';
import { Alert } from '@/components/ui/feedback';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';

function buildSchema(remaining: number) {
  return z
    .object({
      amount: z.coerce.number({ invalid_type_error: 'Enter an amount.' }).gt(0, 'Enter an amount greater than zero.').refine((n) => round2(n) <= round2(remaining), `Amount cannot exceed the remaining balance of ${formatMoney(remaining)}.`),
      method: z.enum(['cash', 'bank_transfer', 'mobile_money', 'card', 'other']),
      reference: z.string().trim().max(80).optional(),
      paidAt: z.string().optional(),
      notes: z.string().trim().max(500).optional(),
    })
    .superRefine((v, ctx) => {
      if (v.method !== 'cash' && !v.reference) ctx.addIssue({ code: 'custom', path: ['reference'], message: 'Enter the transaction reference.' });
      if (v.paidAt && new Date(v.paidAt).getTime() > Date.now()) ctx.addIssue({ code: 'custom', path: ['paidAt'], message: 'Payment date cannot be in the future.' });
    });
}
type Values = z.infer<ReturnType<typeof buildSchema>>;

export function RecordPaymentDialog({ open, onOpenChange, invoiceId, invoiceNumber, remaining }: { open: boolean; onOpenChange: (o: boolean) => void; invoiceId: string; invoiceNumber: string; remaining: number }) {
  const mutation = useRecordPayment();
  const { register, handleSubmit, reset, setError, watch, setValue, formState: { errors } } = useForm<Values>({ resolver: zodResolver(buildSchema(remaining)) });

  useEffect(() => {
    if (open) reset({ amount: remaining, method: 'cash', reference: '', paidAt: '', notes: '' });
  }, [open, remaining, reset]);

  const onSubmit = async (v: Values) => {
    try {
      await mutation.mutateAsync({ invoiceId, payload: { amount: v.amount, method: v.method as PaymentMethod, reference: v.reference, notes: v.notes, paidAt: v.paidAt ? new Date(v.paidAt).toISOString() : undefined } });
      toast.success(`${formatMoney(v.amount)} recorded on ${invoiceNumber}`);
      onOpenChange(false);
    } catch (err) {
      const msg = applyApiErrors(err, setError, ['amount', 'method', 'reference', 'paidAt']);
      if (msg) toast.error(msg);
    }
  };

  const amount = Number(watch('amount')) || 0;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        title="Record payment"
        meta={invoiceNumber}
        footer={
          <>
            <Button variant="ghost" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button onClick={handleSubmit(onSubmit)} loading={mutation.isPending}>Record payment</Button>
          </>
        }
      >
        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
          <div className="flex items-baseline justify-between rounded-md bg-gray-50 px-3.5 py-3">
            <span className="text-xs font-bold tracking-[.1em] text-ink-3">REMAINING</span>
            <span className="font-mono text-xl font-bold text-brand">{formatMoney(remaining)}</span>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField name="amount" label="Amount" type="number" step="0.01" min="0" register={register} errors={errors} required />
            <SelectField name="method" label="Method" register={register} errors={errors} required options={Object.entries(PAYMENT_METHOD_LABELS).map(([value, label]) => ({ value, label }))} />
            <TextField name="reference" label="Reference" register={register} errors={errors} placeholder="TX123456" optional={watch('method') === 'cash'} required={watch('method') !== 'cash'} />
            <TextField name="paidAt" label="Paid at" type="datetime-local" register={register} errors={errors} optional hint="Defaults to now." />
          </div>
          <TextareaField name="notes" label="Notes" register={register} errors={errors} optional rows={2} />
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={() => setValue('amount', remaining, { shouldValidate: true })}>Full balance</Button>
            <Button variant="outline" size="sm" onClick={() => setValue('amount', round2(remaining / 2), { shouldValidate: true })}>Half</Button>
          </div>
          {amount > 0 && amount < remaining && <Alert tone="warning">After this payment {formatMoney(round2(remaining - amount))} remains — the invoice becomes Partial.</Alert>}
        </form>
      </DialogContent>
    </Dialog>
  );
}
