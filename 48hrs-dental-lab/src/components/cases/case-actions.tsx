import { useState } from 'react';
import { toast } from 'sonner';
import { cn } from '@/lib/cn';
import { availableActions, CASE_ACTIONS, type ActionDef } from '@/lib/workflow';
import { useCaseAction } from '@/hooks/api/use-cases';
import { useActor } from '@/hooks/use-auth';
import { errorMessage } from '@/services/api/errors';
import type { CaseActionKey } from '@/types/api';
import type { CaseListItem } from '@/types/models';
import { Button } from '@/components/ui/button';
import { CaseActionDialog, type ActionDialogKind } from './case-action-dialog';

const DIRECT: CaseActionKey[] = ['start_review', 'start_production', 'start_rework', 'confirm_receipt'];

/** Buttons for every workflow step the signed-in user may take on this case right now. */
export function CaseActions({ c, size = 'sm', limit, includeDestructive = false, className, onDone }: { c: CaseListItem; size?: 'sm' | 'md'; limit?: number; includeDestructive?: boolean; className?: string; onDone?: () => void }) {
  const actor = useActor();
  const [dialog, setDialog] = useState<ActionDialogKind | null>(null);
  const mutation = useCaseAction();
  if (!actor) return null;

  let actions: ActionDef[] = availableActions(c, actor).filter((a) => a.key !== 'qc_fail');
  if (!includeDestructive) actions = actions.filter((a) => a.key !== 'cancel' && a.key !== 'reject');
  if (limit) actions = actions.slice(0, limit);

  const runDirect = async (key: CaseActionKey) => {
    try {
      await mutation.mutateAsync({ id: c.id, payload: { action: key } });
      toast.success(`${CASE_ACTIONS[key].label} — ${c.caseNumber}`);
      onDone?.();
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };

  if (!actions.length) return null;

  return (
    <>
      <div className={cn('flex flex-wrap gap-2', className)} onClick={(e) => e.stopPropagation()}>
        {actions.map((a, i) => {
          const label = a.key === 'qc_pass' ? 'Review QC' : a.label;
          const variant = a.variant === 'danger' ? 'danger-soft' : i === 0 ? 'primary' : a.variant === 'primary' ? 'secondary' : a.variant;
          return (
            <Button
              key={a.key}
              size={size}
              variant={variant}
              loading={mutation.isPending && mutation.variables?.payload.action === a.key}
              disabled={mutation.isPending}
              onClick={() => (DIRECT.includes(a.key) ? void runDirect(a.key) : setDialog(a.key === 'qc_pass' ? 'qc' : (a.key as ActionDialogKind)))}
            >
              {label}
            </Button>
          );
        })}
      </div>
      <CaseActionDialog kind={dialog} c={c} onClose={() => setDialog(null)} onDone={onDone} />
    </>
  );
}
