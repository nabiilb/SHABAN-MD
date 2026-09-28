import { forwardRef } from 'react';
import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import * as SwitchPrimitive from '@radix-ui/react-switch';
import { Check, Minus } from 'lucide-react';
import { cn } from '@/lib/cn';

export const Checkbox = forwardRef<HTMLButtonElement, CheckboxPrimitive.CheckboxProps>(({ className, ...props }, ref) => (
  <CheckboxPrimitive.Root
    ref={ref}
    className={cn(
      'flex size-[18px] shrink-0 items-center justify-center rounded-[5px] border border-line-strong bg-card transition-colors data-[state=checked]:border-brand data-[state=checked]:bg-brand data-[state=indeterminate]:border-brand data-[state=indeterminate]:bg-brand disabled:opacity-50',
      className,
    )}
    {...props}
  >
    <CheckboxPrimitive.Indicator className="text-white">
      {props.checked === 'indeterminate' ? <Minus className="size-3.5" strokeWidth={3} /> : <Check className="size-3.5" strokeWidth={3} />}
    </CheckboxPrimitive.Indicator>
  </CheckboxPrimitive.Root>
));
Checkbox.displayName = 'Checkbox';

export const Switch = forwardRef<HTMLButtonElement, SwitchPrimitive.SwitchProps>(({ className, ...props }, ref) => (
  <SwitchPrimitive.Root
    ref={ref}
    className={cn('relative inline-flex h-6 w-11 shrink-0 items-center rounded-full bg-gray-300 transition-colors data-[state=checked]:bg-brand disabled:opacity-50', className)}
    {...props}
  >
    <SwitchPrimitive.Thumb className="block size-5 translate-x-0.5 rounded-full bg-white shadow-sm transition-transform data-[state=checked]:translate-x-[22px]" />
  </SwitchPrimitive.Root>
));
Switch.displayName = 'Switch';
