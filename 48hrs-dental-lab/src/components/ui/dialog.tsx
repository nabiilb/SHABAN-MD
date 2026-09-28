import type { ReactNode } from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useReturnFocus } from '@/hooks/use-return-focus';

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;

interface DialogContentProps {
  title: ReactNode;
  description?: ReactNode;
  /** Mono sub-line under the title (e.g. case number · status), as in the prototype modal. */
  meta?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  className?: string;
}

const sizes = { sm: 'max-w-[440px]', md: 'max-w-[520px]', lg: 'max-w-[680px]', xl: 'max-w-[880px]' };

/** Radix Dialog: traps focus, restores it on close, closes on Esc, labelled for screen readers. */
export function DialogContent({ title, description, meta, children, footer, size = 'md', className }: DialogContentProps) {
  const returnFocus = useReturnFocus();
  return (
    <DialogPrimitive.Portal>
      <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-navy-900/55" />
      <DialogPrimitive.Content
        {...returnFocus}
        // Without a description, opt out explicitly instead of repeating the title to screen readers.
        {...(description ? {} : { 'aria-describedby': undefined })}
        className={cn(
          'fixed top-1/2 left-1/2 z-50 flex max-h-[92dvh] w-[calc(100vw-24px)] -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-lg bg-card shadow-lg focus:outline-none',
          sizes[size],
          className,
        )}
      >
        <div className="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
          <div className="flex min-w-0 flex-col gap-0.5">
            <DialogPrimitive.Title className="text-[17px] font-bold text-ink">{title}</DialogPrimitive.Title>
            {meta && <p className="font-mono text-[12.5px] text-ink-2">{meta}</p>}
            {description && <DialogPrimitive.Description className="text-[13px] text-ink-2">{description}</DialogPrimitive.Description>}
          </div>
          <DialogPrimitive.Close className="-mr-1 rounded-md p-1.5 text-ink-3 hover:bg-gray-100 hover:text-ink" aria-label="Close">
            <X className="size-5" />
          </DialogPrimitive.Close>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-5">{children}</div>
        {footer && <div className="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-gray-50 px-5 py-3.5">{footer}</div>}
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  );
}

/** Left drawer used for the mobile sidebar. */
export function SheetContent({ children, label, className }: { children: ReactNode; label: string; className?: string }) {
  const returnFocus = useReturnFocus();
  return (
    <DialogPrimitive.Portal>
      <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-navy-900/45" />
      <DialogPrimitive.Content {...returnFocus} className={cn('fixed inset-y-0 left-0 z-50 flex w-[min(288px,86vw)] flex-col shadow-lg focus:outline-none', className)}>
        <DialogPrimitive.Title className="sr-only">{label}</DialogPrimitive.Title>
        <DialogPrimitive.Description className="sr-only">{label}</DialogPrimitive.Description>
        {children}
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  );
}
