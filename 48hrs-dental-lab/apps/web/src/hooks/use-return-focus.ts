import { useRef } from 'react';

/**
 * Radix restores focus only to a <Dialog.Trigger>. Our dialogs are mostly
 * controlled (opened from buttons, menu items or shortcuts), so remember
 * whatever had focus when the dialog opened and return focus there on close.
 * Spread the result onto Dialog.Content.
 */
export function useReturnFocus() {
  const opener = useRef<HTMLElement | null>(null);
  return {
    onOpenAutoFocus: () => {
      const el = document.activeElement;
      opener.current = el instanceof HTMLElement && el !== document.body ? el : null;
    },
    onCloseAutoFocus: (e: Event) => {
      const el = opener.current;
      if (el && el.isConnected) {
        e.preventDefault();
        el.focus();
      }
    },
  };
}
