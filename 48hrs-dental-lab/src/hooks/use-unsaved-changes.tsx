import { useCallback, useEffect, useRef } from 'react';
import { useBlocker } from 'react-router-dom';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';

/**
 * Warns before leaving a page with unsaved form changes — both in-app
 * navigation (router blocker + confirm dialog) and tab close / reload
 * (beforeunload). Call allowNavigation() right before navigating away after a
 * successful save.
 */
export function useUnsavedChangesGuard(dirty: boolean) {
  const dirtyRef = useRef(dirty);
  const allowRef = useRef(false);
  dirtyRef.current = dirty;

  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirtyRef.current && !allowRef.current && currentLocation.pathname !== nextLocation.pathname);

  useEffect(() => {
    if (!dirty) return;
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      if (allowRef.current) return;
      e.preventDefault();
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [dirty]);

  const allowNavigation = useCallback(() => {
    allowRef.current = true;
  }, []);

  const element = (
    <ConfirmDialog
      open={blocker.state === 'blocked'}
      onOpenChange={(o) => !o && blocker.state === 'blocked' && blocker.reset()}
      title="Discard unsaved changes?"
      tone="danger"
      confirmLabel="Discard changes"
      description="You have changes that have not been saved. If you leave now they will be lost."
      onConfirm={() => blocker.state === 'blocked' && blocker.proceed()}
    />
  );

  return { element, allowNavigation };
}
