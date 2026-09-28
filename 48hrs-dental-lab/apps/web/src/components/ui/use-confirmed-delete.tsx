import { useState, type ReactNode } from 'react';
import { toast } from 'sonner';
import { errorMessage } from '@/services/api/errors';
import { ConfirmDialog } from './confirm-dialog';

interface Options<T> {
  title: string;
  confirmLabel: string;
  describe: (record: T) => ReactNode;
  remove: (record: T) => Promise<unknown>;
  successMessage: (record: T) => string;
  onDeleted?: (record: T) => void;
}

/**
 * Confirm-then-delete flow used by every list and detail page: call
 * request(record) to open the dialog; render `element` once. API refusals
 * (e.g. "has cases — deactivate instead") surface as an error toast.
 */
export function useConfirmedDelete<T>({ title, confirmLabel, describe, remove, successMessage, onDeleted }: Options<T>) {
  const [target, setTarget] = useState<T | null>(null);
  const [loading, setLoading] = useState(false);

  const element = (
    <ConfirmDialog
      open={target !== null}
      onOpenChange={(o) => !o && !loading && setTarget(null)}
      title={title}
      tone="danger"
      confirmLabel={confirmLabel}
      loading={loading}
      description={target !== null ? describe(target) : null}
      onConfirm={async () => {
        if (target === null) return;
        setLoading(true);
        try {
          await remove(target);
          toast.success(successMessage(target));
          onDeleted?.(target);
        } catch (err) {
          toast.error(errorMessage(err));
        } finally {
          setLoading(false);
          setTarget(null);
        }
      }}
    />
  );

  return { request: setTarget, element };
}
