import { useNavigate } from 'react-router-dom';
import { ArrowRightLeft, Ban, Eye, Pencil, Trash2, UserCog } from 'lucide-react';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import { STATUS_META } from '@48hrs/shared/workflow';
import { useDeleteCase } from '@/hooks/api/use-cases';
import { useAuth } from '@/hooks/use-auth';
import type { CaseListItem } from '@48hrs/shared/types';
import { RowActions } from '@/components/tables/row-actions';
import { useConfirmedDelete } from '@/components/ui/use-confirmed-delete';
import { actionLabel, useCaseWorkflow } from './case-actions';

/**
 * "⋯" menu for a case row: view, edit, every workflow step the user may take
 * (assign, start production, QC, dispatch…), cancel and delete — each gated
 * by the same permission/workflow rules the API enforces.
 */
export function CaseRowActions({ c }: { c: CaseListItem }) {
  const { can } = useAuth();
  const navigate = useNavigate();
  const wf = useCaseWorkflow(c, { includeDestructive: true });
  const del = useDeleteCase();
  const { request: requestDelete, element: deleteDialog } = useConfirmedDelete<CaseListItem>({
    title: 'Delete case?',
    confirmLabel: 'Delete case',
    describe: (r) => <>This permanently removes <b className="font-mono">{r.caseNumber}</b>, its files, history and invoice. Cases with recorded payments cannot be deleted — cancel them instead.</>,
    remove: (r) => del.mutateAsync(r.id),
    successMessage: (r) => `${r.caseNumber} deleted`,
  });
  const open = STATUS_META[c.status].open;

  const workflow = wf.actions.map((a) => ({
    label: actionLabel(a),
    icon: a.key === 'assign' ? <UserCog /> : a.key === 'cancel' || a.key === 'reject' ? <Ban /> : <ArrowRightLeft />,
    tone: a.variant === 'danger' ? ('danger' as const) : undefined,
    onSelect: () => void wf.run(a),
  }));

  return (
    <>
      <RowActions
        label={c.caseNumber}
        actions={[
          { label: 'View case', icon: <Eye />, onSelect: () => navigate(`/cases/${c.id}`) },
          { label: 'Edit details', icon: <Pencil />, onSelect: () => navigate(`/cases/${c.id}?edit=1`), hidden: !can(PERMISSIONS.CASES_EDIT) || !open },
          ...workflow.filter((w) => !w.tone),
          ...workflow.filter((w) => w.tone),
          { label: 'Delete case', icon: <Trash2 />, tone: 'danger', onSelect: () => requestDelete(c), hidden: !can(PERMISSIONS.CASES_DELETE) },
        ]}
      />
      {wf.dialogElement}
      {deleteDialog}
    </>
  );
}
