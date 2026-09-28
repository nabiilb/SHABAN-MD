import type { ReactNode } from 'react';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { cn } from '@/lib/cn';

export const Menu = DropdownMenu.Root;
export const MenuTrigger = DropdownMenu.Trigger;

export function MenuContent({ children, align = 'end', className }: { children: ReactNode; align?: 'start' | 'end' | 'center'; className?: string }) {
  return (
    <DropdownMenu.Portal>
      <DropdownMenu.Content align={align} sideOffset={6} className={cn('z-50 min-w-48 rounded-md border border-line bg-card p-1 shadow-lg', className)}>
        {children}
      </DropdownMenu.Content>
    </DropdownMenu.Portal>
  );
}

export function MenuItem({ children, onSelect, tone, disabled }: { children: ReactNode; onSelect?: () => void; tone?: 'danger'; disabled?: boolean }) {
  return (
    <DropdownMenu.Item
      disabled={disabled}
      onSelect={onSelect}
      className={cn(
        'flex cursor-pointer items-center gap-2 rounded-sm px-2.5 py-2 text-[13px] font-medium text-ink outline-none select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[highlighted]:bg-navy-50 [&_svg]:size-4 [&_svg]:text-ink-3',
        tone === 'danger' && 'text-danger data-[highlighted]:bg-danger-bg [&_svg]:text-danger',
      )}
    >
      {children}
    </DropdownMenu.Item>
  );
}

export function MenuLabel({ children }: { children: ReactNode }) {
  return <DropdownMenu.Label className="px-2.5 pt-2 pb-1 text-[11px] font-bold tracking-[.12em] text-ink-3 uppercase">{children}</DropdownMenu.Label>;
}

export function MenuSeparator() {
  return <DropdownMenu.Separator className="my-1 h-px bg-line" />;
}

export const MenuCheckboxItem = ({ children, checked, onCheckedChange }: { children: ReactNode; checked: boolean; onCheckedChange: (v: boolean) => void }) => (
  <DropdownMenu.CheckboxItem
    checked={checked}
    onCheckedChange={onCheckedChange}
    onSelect={(e) => e.preventDefault()}
    className="flex cursor-pointer items-center gap-2 rounded-sm px-2.5 py-2 text-[13px] text-ink outline-none select-none data-[highlighted]:bg-navy-50"
  >
    <span className={cn('flex size-4 items-center justify-center rounded-[4px] border', checked ? 'border-brand bg-brand text-white' : 'border-line-strong')} aria-hidden>
      {checked && <svg viewBox="0 0 12 12" className="size-3" fill="none" stroke="currentColor" strokeWidth="2"><path d="M2.5 6.5 5 9l4.5-6" /></svg>}
    </span>
    {children}
  </DropdownMenu.CheckboxItem>
);
