import { useMemo } from 'react';
import { Check, Lock, Minus } from 'lucide-react';
import { toast } from 'sonner';
import { cn } from '@/lib/cn';
import { usePermissionCatalogue, useRoles, useUpdateRole } from '@/hooks/api/use-admin';
import { usePageTitle } from '@/hooks/use-page-title';
import { errorMessage } from '@/services/api/errors';
import type { Role } from '@/types/models';
import { Alert, PageLoader } from '@/components/ui/feedback';

import { QueryError } from '@/components/ui/query-error';
export default function RolesPage() {
  usePageTitle('Roles & Permissions', 'Who can do what — enforced by the API, reflected in the UI');
  const roles = useRoles();
  const catalogue = usePermissionCatalogue();
  const update = useUpdateRole();

  const perms = catalogue.data;
  const groups = useMemo(() => {
    const map = new Map<string, NonNullable<typeof perms>>();
    (perms ?? []).forEach((p) => map.set(p.group, [...(map.get(p.group) ?? []), p]));
    return [...map.entries()];
  }, [perms]);

  if (roles.isLoading || catalogue.isLoading) return <PageLoader />;
  if (roles.error || catalogue.error) return <QueryError error={roles.error ?? catalogue.error} onRetry={() => { void roles.refetch(); void catalogue.refetch(); }} />;

  const toggle = async (role: Role, key: string) => {
    const has = role.permissions.includes(key);
    const permissions = has ? role.permissions.filter((p) => p !== key) : [...role.permissions, key];
    try {
      await update.mutateAsync({ key: role.key, permissions });
      toast.success(`${role.name}: ${has ? 'revoked' : 'granted'} “${catalogue.data?.find((p) => p.key === key)?.label}”`);
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };

  const list = roles.data ?? [];
  return (
    <div className="flex flex-col gap-4">
      <Alert>Click a cell to grant or revoke. Changes apply immediately across the system — revoke “Perform quality control” and the QC buttons disappear for that role, and the API refuses the action.</Alert>
      <div className="overflow-hidden rounded-md border border-line bg-card shadow-sm">
        <div className="max-h-[calc(100dvh-240px)] overflow-auto">
          <table className="w-full min-w-[900px] border-collapse text-sm">
            <thead className="sticky top-0 z-10 bg-gray-50">
              <tr>
                <th scope="col" className="sticky left-0 bg-gray-50 px-4 py-3 text-left text-[11px] font-bold tracking-[.12em] text-ink-3 uppercase">Action</th>
                {list.map((r) => (
                  <th key={r.key} scope="col" className="px-2 py-3 text-center text-[11px] font-bold tracking-[.08em] whitespace-nowrap text-ink-3 uppercase" title={r.description}>
                    <span className="inline-flex items-center gap-1">{r.locked && <Lock className="size-3" aria-label="Locked" />}{r.name}</span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {groups.map(([group, perms]) => (
                <GroupRows key={group} group={group} perms={perms} roles={list} onToggle={toggle} busy={update.isPending} colSpan={list.length + 1} />
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function GroupRows({ group, perms, roles, onToggle, busy, colSpan }: { group: string; perms: { key: string; label: string }[]; roles: Role[]; onToggle: (r: Role, k: string) => void; busy: boolean; colSpan: number }) {
  return (
    <>
      <tr className="border-t border-line bg-navy-50/60">
        <th colSpan={colSpan} scope="colgroup" className="px-4 py-2 text-left text-[11px] font-extrabold tracking-[.14em] text-brand uppercase">{group}</th>
      </tr>
      {perms.map((p) => (
        <tr key={p.key} className="border-t border-line">
          <th scope="row" className="sticky left-0 bg-card px-4 py-2 text-left font-semibold">
            {p.label}
            <span className="block font-mono text-[11px] font-normal text-ink-3">{p.key}</span>
          </th>
          {roles.map((r) => {
            const on = r.permissions.includes(p.key);
            return (
              <td key={r.key} className="px-2 py-1.5 text-center">
                <button
                  type="button"
                  disabled={r.locked || busy}
                  onClick={() => onToggle(r, p.key)}
                  aria-pressed={on}
                  aria-label={`${on ? 'Revoke' : 'Grant'} ${p.label} for ${r.name}`}
                  className={cn('inline-flex h-[30px] w-[34px] items-center justify-center rounded-md transition-colors disabled:cursor-not-allowed', on ? 'bg-success-bg text-success hover:bg-success-bg-hover' : 'bg-gray-100 text-gray-400 hover:bg-gray-200', r.locked && 'opacity-70')}
                >
                  {on ? <Check className="size-4" strokeWidth={3} /> : <Minus className="size-4" />}
                </button>
              </td>
            );
          })}
        </tr>
      ))}
    </>
  );
}
