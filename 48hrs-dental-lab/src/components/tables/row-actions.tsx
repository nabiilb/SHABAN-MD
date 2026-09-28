import { Fragment, useRef, type ReactNode } from 'react';
import { MoreHorizontal } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Menu, MenuContent, MenuItem, MenuSeparator, MenuTrigger } from '@/components/ui/menu';

export interface RowAction {
  label: string;
  icon?: ReactNode;
  onSelect: () => void;
  tone?: 'danger';
  /** Omit the item (e.g. the user lacks the permission). */
  hidden?: boolean;
}

/** "⋯" menu for a table row. Renders nothing when every action is hidden. */
export function RowActions({ label, actions }: { label: string; actions: RowAction[] }) {
  const triggerRef = useRef<HTMLButtonElement>(null);
  const visible = actions.filter((a) => !a.hidden);
  if (!visible.length) return null;
  const firstDanger = visible.findIndex((a) => a.tone === 'danger');
  return (
    <Menu>
      <MenuTrigger asChild>
        <Button ref={triggerRef} variant="ghost" size="icon-sm" aria-label={`Actions for ${label}`} onClick={(e) => e.stopPropagation()}>
          <MoreHorizontal />
        </Button>
      </MenuTrigger>
      <MenuContent>
        {visible.map((a, i) => (
          <Fragment key={a.label}>
            {i === firstDanger && i > 0 && <MenuSeparator />}
            {/* Let the menu close first, and put focus back on this row's trigger ourselves, so a dialog the action opens returns focus here. */}
            <MenuItem
              tone={a.tone}
              onSelect={() =>
                window.setTimeout(() => {
                  triggerRef.current?.focus();
                  a.onSelect();
                }, 0)
              }
            >
              {a.icon}
              {a.label}
            </MenuItem>
          </Fragment>
        ))}
      </MenuContent>
    </Menu>
  );
}
