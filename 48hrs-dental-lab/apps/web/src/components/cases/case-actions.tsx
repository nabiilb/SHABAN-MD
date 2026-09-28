import { useState } from 'react';
import { toast } from 'sonner';
import { cn } from '@/lib/cn';
import { availableActions, type ActionDef } from '@48hrs/shared/workflow';
import { useCaseAction } from '@/hooks/api/use-cases';
import { useActor } from '@/hooks/use-auth';
import { errorMessage } from '@/services/api/errors';
import type { CaseActionKey } from '@48hrs/shared/types';
import type { CaseListItem } from '@48hrs/shared/types';
import { Button } from '@/components/ui/button';
import { CaseActionDialog, type ActionDialogKind } from './case-action-dialog';

export function actionLabel(a: ActionDef) {
  return a.key === 'qc_pass' ? 'Review QC' : a.label;
}

/**
 * Workflow actions the signed-in user may take on a case right now (from
 * lib/workflow.canPerformAction), plus a runner: actions that need input open
 * the action dialog, the rest run immediately. Shared by buttons and row menus.
 */
export function useCaseWorkflow(c: CaseListItem, { includeDestructive = false, onDone }: { includeDestructive?: boolean; onDone?: () => void } = {}) {
  const actor = useActor();
  const [dialog, setDialog] = useState<ActionDialogKind | null>(null);
  const mutation = useCaseAction();

  let actions: ActionDef[] = actor ? availableActions(c, actor).filter((a) => a.key !== 'qc_fail') : [];
  if (!includeDestructive) actions = actions.filter((a) => a.key !== 'cancel' && a.key !== 'reject');

  const run = async (a: ActionDef) => {
    if (a.needsInput) {
      setDialog(a.key === 'qc_pass' ? 'qc' : (a.key as ActionDialogKind));
      return;
    }
    try {
      await mutation.mutateAsync({ id: c.id, payload: { action: a.key } });
      toast.success(`${a.label} — ${c.caseNumber}`);
      onDone?.();
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };

  const dialogElement = <CaseActionDialog kind={dialog} c={c} onClose={() => setDialog(null)} onDone={onDone} />;
  const pendingKey: CaseActionKey | null = mutation.isPending ? mutation.variables?.payload.action ?? null : null;
  return { actions, run, dialogElement, pendingKey, busy: mutation.isPending };
}

/** Buttons for every workflow step the signed-in user may take on this case right now. */
export function CaseActions({ c, size = 'sm', limit, includeDestructive = false, className, onDone }: { c: CaseListItem; size?: 'sm' | 'md'; limit?: number; includeDestructive?: boolean; className?: string; onDone?: () => void }) {
  const wf = useCaseWorkflow(c, { includeDestructive, onDone });
  const shown = limit ? wf.actions.slice(0, limit) : wf.actions;
  if (!shown.length) return null;

  return (
    <>
      <div className={cn('flex flex-wrap gap-2', className)} onClick={(e) => e.stopPropagation()}>
        {shown.map((a, i) => {
          const variant = a.variant === 'danger' ? 'danger-soft' : i === 0 ? 'primary' : a.variant === 'primary' ? 'secondary' : a.variant;
          return (
            <Button key={a.key} size={size} variant={variant} loading={wf.pendingKey === a.key} disabled={wf.busy} onClick={() => void wf.run(a)}>
              {actionLabel(a)}
            </Button>
          );
        })}
      </div>
      {wf.dialogElement}
    </>
  );
}

