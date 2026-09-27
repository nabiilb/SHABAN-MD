import type { ReactNode } from 'react';
import { Controller, type Control, type FieldPath, type FieldValues, type UseFormRegister, type FieldErrors, get } from 'react-hook-form';
import { Checkbox, Switch } from '@/components/ui/checkbox';
import { Input, NativeSelect, Textarea } from '@/components/ui/input';
import { FormField } from './form-field';

function errorOf<T extends FieldValues>(errors: FieldErrors<T>, name: string): string | undefined {
  const e = get(errors, name) as { message?: string } | undefined;
  return e?.message;
}

interface BaseProps<T extends FieldValues> {
  name: FieldPath<T>;
  label: ReactNode;
  errors: FieldErrors<T>;
  hint?: ReactNode;
  required?: boolean;
  optional?: boolean;
  className?: string;
}

export function TextField<T extends FieldValues>({ name, label, register, errors, hint, required, optional, className, ...input }: BaseProps<T> & { register: UseFormRegister<T> } & Omit<React.InputHTMLAttributes<HTMLInputElement>, 'name'>) {
  const error = errorOf(errors, name);
  return (
    <FormField label={label} error={error} hint={hint} required={required} optional={optional} className={className}>
      {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} aria-required={required} {...input} {...register(name, input.type === 'number' ? { valueAsNumber: true } : undefined)} />}
    </FormField>
  );
}

export function TextareaField<T extends FieldValues>({ name, label, register, errors, hint, required, optional, className, ...input }: BaseProps<T> & { register: UseFormRegister<T> } & Omit<React.TextareaHTMLAttributes<HTMLTextAreaElement>, 'name'>) {
  const error = errorOf(errors, name);
  return (
    <FormField label={label} error={error} hint={hint} required={required} optional={optional} className={className}>
      {({ id, describedBy, invalid }) => <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} {...input} {...register(name)} />}
    </FormField>
  );
}

export function SelectField<T extends FieldValues>({ name, label, register, errors, hint, required, optional, className, options, placeholder, ...input }: BaseProps<T> & { register: UseFormRegister<T>; options: { value: string; label: string }[]; placeholder?: string } & Omit<React.SelectHTMLAttributes<HTMLSelectElement>, 'name'>) {
  const error = errorOf(errors, name);
  return (
    <FormField label={label} error={error} hint={hint} required={required} optional={optional} className={className}>
      {({ id, describedBy, invalid }) => (
        <NativeSelect id={id} aria-describedby={describedBy} aria-invalid={invalid} {...input} {...register(name)}>
          {placeholder !== undefined && <option value="">{placeholder}</option>}
          {options.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </NativeSelect>
      )}
    </FormField>
  );
}

/** Date or date-time picker (native pickers: accessible, localised and mobile-friendly). */
export function DateField<T extends FieldValues>({ withTime, ...props }: BaseProps<T> & { register: UseFormRegister<T>; withTime?: boolean; min?: string; max?: string }) {
  return <TextField {...props} type={withTime ? 'datetime-local' : 'date'} />;
}

export function CheckboxField<T extends FieldValues>({ name, control, label, description }: { name: FieldPath<T>; control: Control<T>; label: ReactNode; description?: ReactNode }) {
  return (
    <Controller
      name={name}
      control={control}
      render={({ field }) => (
        <label className="flex cursor-pointer items-start gap-3">
          <Checkbox checked={!!field.value} onCheckedChange={(v) => field.onChange(!!v)} onBlur={field.onBlur} className="mt-0.5" />
          <span className="flex flex-col gap-0.5">
            <span className="text-sm font-semibold text-ink">{label}</span>
            {description && <span className="text-xs text-ink-3">{description}</span>}
          </span>
        </label>
      )}
    />
  );
}

export function SwitchField<T extends FieldValues>({ name, control, label, description }: { name: FieldPath<T>; control: Control<T>; label: ReactNode; description?: ReactNode }) {
  return (
    <Controller
      name={name}
      control={control}
      render={({ field }) => (
        <div className="flex items-center justify-between gap-4 rounded-md border border-line px-3.5 py-3">
          <span className="flex flex-col gap-0.5">
            <span className="text-sm font-semibold text-ink" id={`${name}-label`}>{label}</span>
            {description && <span className="text-xs text-ink-3">{description}</span>}
          </span>
          <Switch checked={!!field.value} onCheckedChange={field.onChange} aria-labelledby={`${name}-label`} />
        </div>
      )}
    />
  );
}
