import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/cn';

/** Variants follow the prototype's design-system Button: primary, secondary, ghost, danger. */
export const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-400 disabled:pointer-events-none disabled:bg-gray-200 disabled:text-gray-400 disabled:border-transparent [&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        primary: 'bg-brand text-white hover:bg-brand-hover active:bg-brand-active',
        secondary: 'bg-white text-brand border-[1.5px] border-brand hover:bg-navy-50',
        outline: 'bg-card text-ink border border-line-strong hover:bg-gray-50',
        ghost: 'bg-transparent text-ink hover:bg-gray-100',
        danger: 'bg-danger text-white hover:bg-danger-hover',
        'danger-soft': 'bg-danger-bg text-danger hover:bg-danger-bg-hover',
        link: 'h-auto px-0 text-navy-500 underline-offset-4 hover:underline hover:text-navy-600',
      },
      size: {
        sm: 'h-8 px-3 text-[13px]',
        md: 'h-10 px-4 text-sm',
        lg: 'h-12 px-5 text-[15px]',
        icon: 'size-10 p-0',
        'icon-sm': 'size-8 p-0',
      },
    },
    defaultVariants: { variant: 'primary', size: 'md' },
  },
);

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
  asChild?: boolean;
  loading?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, asChild, loading, disabled, children, type, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';
    return (
      <Comp
        ref={ref}
        type={asChild ? undefined : (type ?? 'button')}
        className={cn(buttonVariants({ variant, size }), className)}
        disabled={asChild ? undefined : disabled || loading}
        aria-busy={loading || undefined}
        {...props}
      >
        {asChild ? (
          children
        ) : (
          <>
            {loading && <Loader2 className="animate-spin" aria-hidden />}
            {children}
          </>
        )}
      </Comp>
    );
  },
);
Button.displayName = 'Button';
