import { Link } from 'react-router-dom';
import { Paperclip } from 'lucide-react';
import { nextActorLabel } from '@48hrs/shared/workflow';
import type { CaseListItem } from '@48hrs/shared/types';
import { useAuth } from '@/hooks/use-auth';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import { formatMoney } from '@/utils/format';
import { Button } from '@/components/ui/button';
import { PriorityBadge, StatusBadge } from './badges';
import { CaseActions } from './case-actions';
import { SlaBar } from './sla';

/** Queue card from the prototype: case id, patient, status pill, service, SLA bar, next actor, actions. */
export function CaseCard({ c }: { c: CaseListItem }) {
  const { can } = useAuth();
  const showMoney = can([PERMISSIONS.INVOICES_VIEW, PERMISSIONS.PAYMENTS_VIEW], 'any');
  return (
    <article className="flex min-w-0 flex-col gap-3 rounded-md border border-line bg-card p-4 shadow-sm">
      <div className="flex items-start justify-between gap-2.5">
        <div className="flex min-w-0 flex-col gap-0.5">
          <Link to={`/cases/${c.id}`} className="font-mono text-[12.5px] font-bold tracking-[.02em] text-brand hover:underline">
            {c.caseNumber}
          </Link>
          <span className="text-[15.5px] leading-tight font-bold text-ink">{c.patient.name}</span>
          <span className="flex flex-wrap items-center gap-x-1.5 text-[12.5px] text-ink-2">
            <span className="truncate">{c.clinic.name}</span>
            <span className="inline-flex items-center gap-1 whitespace-nowrap text-ink-3">
              <Paperclip className="size-3" aria-hidden /> {c.attachmentCount} file{c.attachmentCount === 1 ? '' : 's'}
            </span>
          </span>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1.5">
          <StatusBadge status={c.status} />
          <PriorityBadge priority={c.priority} hideNormal />
        </div>
      </div>

      <dl className="flex flex-wrap gap-x-5 gap-y-1.5">
        <div className="flex flex-col">
          <dt className="text-[11px] tracking-[.06em] text-ink-3">SERVICE</dt>
          <dd className="text-[13.5px] font-semibold">{c.restorationType} · {c.units} unit{c.units === 1 ? '' : 's'}</dd>
        </div>
        {showMoney && (
          <div className="flex flex-col">
            <dt className="text-[11px] tracking-[.06em] text-ink-3">TOTAL</dt>
            <dd className="font-mono text-[13.5px] font-bold">{formatMoney(c.total)}</dd>
          </div>
        )}
        <div className="flex flex-col">
          <dt className="text-[11px] tracking-[.06em] text-ink-3">{c.technician ? 'TECHNICIAN' : 'SHADE'}</dt>
          <dd className="text-[13.5px] font-semibold">{c.technician?.name ?? c.shade}</dd>
        </div>
      </dl>

      <SlaBar c={c} />

      <p className="text-[12.5px] text-ink-2">
        {nextActorLabel(c) ? <>Next action: <b className="font-semibold text-ink">{nextActorLabel(c)}</b></> : 'Closed — no further action.'}
      </p>

      <div className="mt-auto flex flex-wrap items-center gap-2">
        <CaseActions c={c} limit={2} />
        <Button asChild variant="ghost" size="sm">
          <Link to={`/cases/${c.id}`}>View case</Link>
        </Button>
      </div>
    </article>
  );
}

export function CaseCardGrid({ children }: { children: React.ReactNode }) {
  return <div className="grid grid-cols-[repeat(auto-fill,minmax(min(100%,320px),1fr))] gap-3.5">{children}</div>;
}
