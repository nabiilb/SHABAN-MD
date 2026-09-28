import { useId, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Label } from '@/components/ui/input';

interface FormFieldProps {
  label: ReactNode;
  error?: string;
  hint?: ReactNode;
  required?: boolean;
  optional?: boolean;
  className?: string;
  /** Render prop receives the ids to wire label/description/error for screen readers. */
  children: (ids: { id: string; describedBy: string | undefined; invalid: boolean }) => ReactNode;
}

export function FormField({ label, error, hint, required, optional, className, children }: FormFieldProps) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;
  return (
    <div className={cn('flex min-w-0 flex-col gap-1.5', className)}>
      <Label htmlFor={id} required={required} optional={optional}>
        {label}
      </Label>
      {children({ id, describedBy, invalid: !!error })}
      {hint && !error && (
        <p id={hintId} className="text-xs text-ink-3">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} className="text-xs font-semibold text-danger" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
