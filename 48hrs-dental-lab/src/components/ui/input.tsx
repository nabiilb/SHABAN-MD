import { forwardRef, type InputHTMLAttributes, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/cn';

const fieldBase =
  'w-full rounded-md border border-line-strong bg-card px-3 text-sm text-ink placeholder:text-ink-3 transition-colors focus:border-navy-400 focus:outline-none focus:ring-3 focus:ring-navy-400/30 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500 aria-[invalid=true]:border-danger aria-[invalid=true]:focus:ring-danger/25';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(({ className, ...props }, ref) => (
  <input ref={ref} className={cn(fieldBase, 'h-10', className)} {...props} />
));
Input.displayName = 'Input';

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(({ className, rows = 4, ...props }, ref) => (
  <textarea ref={ref} rows={rows} className={cn(fieldBase, 'min-h-20 resize-y py-2.5 leading-relaxed', className)} {...props} />
));
Textarea.displayName = 'Textarea';

/** Native select: accessible, keyboard- and mobile-friendly by default. */
export const NativeSelect = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(({ className, children, ...props }, ref) => (
  <div className="relative">
    <select ref={ref} className={cn(fieldBase, 'h-10 appearance-none pr-9', className)} {...props}>
      {children}
    </select>
    <ChevronDown className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-ink-3" aria-hidden />
  </div>
));
NativeSelect.displayName = 'NativeSelect';

export function Label({ className, children, required, optional, ...props }: React.LabelHTMLAttributes<HTMLLabelElement> & { required?: boolean; optional?: boolean }) {
  return (
    <label className={cn('text-xs font-bold text-ink-2', className)} {...props}>
      {children}
      {required && <span className="ml-0.5 text-danger" aria-hidden>*</span>}
      {optional && <span className="ml-1.5 font-semibold text-ink-3">optional</span>}
    </label>
  );
}
