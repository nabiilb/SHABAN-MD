import { useMemo } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import type { ColumnDef } from '@tanstack/react-table';
import { Download, Plus } from 'lucide-react';
import { CASE_TYPE_LABELS, PAYMENT_STATUS_META, PRIORITY_META } from '@48hrs/shared/constants';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import { getSlaInfo, formatRemaining } from '@48hrs/shared/sla';
import { ALL_STATUSES, DONE_STATUSES, OPEN_STATUSES, STATUS_META } from '@48hrs/shared/workflow';
import { useCases } from '@/hooks/api/use-cases';
import { useClinics, useDoctors, useTechnicians } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { useSlaConfig } from '@/hooks/use-sla';
import { useListState } from '@/hooks/use-list-state';
import type { CaseListParams, SlaFilter } from '@48hrs/shared/types';
import type { CasePriority, CaseStatus, CaseType, CaseListItem, PaymentStatus } from '@48hrs/shared/types';
import { downloadCsv } from '@/utils/download';
import { formatDateTime, formatMoney, formatRelativeDay } from '@/utils/format';
import { PaymentBadge, PriorityBadge, StatusBadge } from '@/components/cases/badges';
import { CaseActions } from '@/components/cases/case-actions';
import { CaseRowActions } from '@/components/cases/case-row-actions';
import { SlaCell } from '@/components/cases/sla';
import { StageMini } from '@/components/cases/stage-progress';
import { DataTable } from '@/components/tables/data-table';
import { ClearFiltersButton, CollapsibleFilters, DateFilter, FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Button } from '@/components/ui/button';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { serverClock } from '@/services/api/server-clock';

const DEFAULTS = {
  view: 'all',
  search: '',
  status: undefined as string | undefined,
  priority: undefined as string | undefined,
  technicianId: undefined as string | undefined,
  doctorId: undefined as string | undefined,
  clinicId: undefined as string | undefined,
  caseType: undefined as string | undefined,
  paymentStatus: undefined as string | undefined,
  sla: undefined as string | undefined,
  from: undefined as string | undefined,
  to: undefined as string | undefined,
  dueFrom: undefined as string | undefined,
  dueTo: undefined as string | undefined,
  sort: 'receivedAt',
  dir: 'desc',
  page: '1',
  perPage: '20',
};

export default function CasesListPage() {
  usePageTitle('Cases', 'Every case, its stage and its 48-hour deadline');
  const { can, user } = useAuth();
  const navigate = useNavigate();
  const list = useListState(DEFAULTS);
  const { state: f, set: setF, reset: resetF } = list;
  const slaConfig = useSlaConfig();

  const isScopedTech = !!user?.technicianId && !can(PERMISSIONS.CASES_VIEW_ALL);
  const isClient = !!user?.clinicId && !can(PERMISSIONS.CASES_VIEW_ALL);
  const techs = useTechnicians({ perPage: 100 }, can([PERMISSIONS.TECHNICIANS_VIEW, PERMISSIONS.CASES_ASSIGN], 'any') && !isScopedTech);
  const doctors = useDoctors({ perPage: 200 }, can(PERMISSIONS.DOCTORS_VIEW));
  const clinics = useClinics({ perPage: 200 }, can(PERMISSIONS.CLINICS_VIEW) && !isClient);

  const params: CaseListParams = {
    search: f.search || undefined,
    status: f.status ? [f.status as CaseStatus] : f.view === 'open' ? OPEN_STATUSES : f.view === 'done' ? DONE_STATUSES : undefined,
    priority: f.priority ? [f.priority as CasePriority] : undefined,
    technicianId: f.technicianId,
    doctorId: f.doctorId,
    clinicId: f.clinicId,
    caseType: f.caseType as CaseType | undefined,
    paymentStatus: f.paymentStatus as PaymentStatus | undefined,
    sla: f.sla as SlaFilter | undefined,
    from: f.from,
    to: f.to,
    dueFrom: f.dueFrom,
    dueTo: f.dueTo,
    sort: f.sort,
    dir: f.dir as 'asc' | 'desc',
    page: Number(f.page),
    perPage: Number(f.perPage),
  };
  const query = useCases(params);
  const showMoney = can([PERMISSIONS.INVOICES_VIEW, PERMISSIONS.PAYMENTS_VIEW], 'any');

  const columns = useMemo<ColumnDef<CaseListItem, unknown>[]>(
    () => [
      {
        id: 'caseNumber',
        header: 'Case ID',
        meta: { sortKey: 'caseNumber' },
        cell: ({ row }) => (
          <Link to={`/cases/${row.original.id}`} onClick={(e) => e.stopPropagation()} className="font-mono text-[13px] font-bold whitespace-nowrap text-brand hover:underline">
            {row.original.caseNumber}
          </Link>
        ),
      },
      {
        id: 'patient',
        header: 'Patient',
        meta: { sortKey: 'patient', label: 'Patient' },
        cell: ({ row }) => (
          <div className="flex flex-col">
            <span className="font-semibold whitespace-nowrap">{row.original.patient.name}</span>
            <span className="font-mono text-[11.5px] text-ink-3">{row.original.patient.code}</span>
          </div>
        ),
      },
      {
        id: 'doctor',
        header: 'Doctor / clinic',
        meta: { sortKey: 'doctor', label: 'Doctor' },
        cell: ({ row }) => (
          <div className="flex flex-col">
            <span className="whitespace-nowrap">{row.original.doctor.name}</span>
            <span className="text-[11.5px] whitespace-nowrap text-ink-3">{row.original.clinic.name}</span>
          </div>
        ),
      },
      { id: 'clinic', header: 'Clinic', meta: { sortKey: 'clinic', label: 'Clinic', cellClassName: 'whitespace-nowrap text-ink-2', defaultHidden: true }, cell: ({ row }) => row.original.clinic.name },
      {
        id: 'caseType',
        header: 'Case type',
        meta: { sortKey: 'caseType', label: 'Case type' },
        cell: ({ row }) => (
          <div className="flex flex-col">
            <span className="font-medium whitespace-nowrap">{row.original.restorationType}</span>
            <span className="text-[11.5px] text-ink-3">{row.original.units} unit{row.original.units === 1 ? '' : 's'} · {row.original.shade}</span>
          </div>
        ),
      },
      { id: 'priority', header: 'Priority', meta: { sortKey: 'priority', label: 'Priority' }, cell: ({ row }) => <PriorityBadge priority={row.original.priority} /> },
      {
        id: 'receivedAt',
        header: 'Received',
        meta: { sortKey: 'receivedAt', label: 'Received at', cellClassName: 'whitespace-nowrap text-[13px] text-ink-2' },
        cell: ({ row }) => (row.original.receivedAt ? formatRelativeDay(row.original.receivedAt) : <span className="text-ink-3">Awaiting intake</span>),
      },
      { id: 'dueAt', header: 'Due / SLA', meta: { sortKey: 'dueAt', label: 'Due at' }, cell: ({ row }) => <SlaCell c={row.original} /> },
      { id: 'stage', header: 'Stage', meta: { sortKey: 'status', label: 'Current stage' }, cell: ({ row }) => <StageMini status={row.original.status} /> },
      { id: 'technician', header: 'Technician', meta: { sortKey: 'technician', label: 'Technician', cellClassName: 'whitespace-nowrap' }, cell: ({ row }) => row.original.technician?.name ?? <span className="text-ink-3">Unassigned</span> },
      { id: 'status', header: 'Status', meta: { label: 'Status' }, cell: ({ row }) => <StatusBadge status={row.original.status} /> },
      ...(showMoney
        ? ([
            {
              id: 'payment',
              header: 'Payment',
              meta: { sortKey: 'paymentStatus', label: 'Payment' },
              cell: ({ row }) => (
                <div className="flex flex-col items-start gap-1">
                  <PaymentBadge status={row.original.paymentStatus} />
                  <span className="font-mono text-[11.5px] text-ink-3">{formatMoney(row.original.total)}</span>
                </div>
              ),
            },
          ] as ColumnDef<CaseListItem, unknown>[])
        : []),
      {
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        enableHiding: false,
        meta: { align: 'right' },
        cell: ({ row }) => (
          <div className="flex items-center justify-end gap-1.5">
            <CaseActions c={row.original} limit={1} />
            <CaseRowActions c={row.original} />
          </div>
        ),
      },
    ],
    [showMoney],
  );

  const exportRows = (rows: CaseListItem[]) => {
    const now = serverClock.now();
    downloadCsv(
      `cases-${new Date().toISOString().slice(0, 10)}.csv`,
      ['Case ID', 'Patient', 'Patient ref', 'Doctor', 'Clinic', 'Service', 'Units', 'Shade', 'Priority', 'Received', 'Due', 'SLA', 'Status', 'Technician', ...(showMoney ? ['Total', 'Payment'] : [])],
      rows.map((c) => [
        c.caseNumber, c.patient.name, c.patient.code, c.doctor.name, c.clinic.name, c.restorationType, c.units, c.shade, PRIORITY_META[c.priority].label,
        formatDateTime(c.receivedAt), formatDateTime(c.dueAt), formatRemaining(getSlaInfo(c, now, slaConfig)), STATUS_META[c.status].label, c.technician?.name ?? '',
        ...(showMoney ? [c.total, PAYMENT_STATUS_META[c.paymentStatus].label] : []),
      ]),
    );
  };

  const { filtersActive } = list;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <SegmentedControl
          label="Case views"
          value={f.view}
          onChange={(v) => setF({ view: v, status: undefined })}
          options={[{ value: 'all', label: 'All cases' }, { value: 'open', label: 'Open' }, { value: 'done', label: 'Delivered' }]}
        />
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => query.data && exportRows(query.data.data)} disabled={!query.data?.data.length}>
            <Download /> Export page
          </Button>
          {can([PERMISSIONS.CASES_CREATE, PERMISSIONS.CASES_SUBMIT], 'any') && (
            <Button asChild>
              <Link to="/cases/new"><Plus /> New case</Link>
            </Button>
          )}
        </div>
      </div>

      <DataTable
        caption="Cases"
        columns={columns}
        data={query.data?.data}
        meta={query.data?.meta}
        getRowId={(c) => c.id}
        isLoading={query.isLoading}
        isFetching={query.isFetching}
        error={query.error}
        onRetry={() => void query.refetch()}
        {...list.tableProps}
        onRowClick={(c) => navigate(`/cases/${c.id}`)}
        enableColumnToggle
        emptyTitle="No cases found."
        emptyDescription={filtersActive ? 'Try removing a filter or searching for something else.' : 'Cases appear here as soon as they are registered.'}
        emptyAction={filtersActive ? <Button variant="outline" size="sm" onClick={resetF}>Clear filters</Button> : undefined}
        bulkActions={(rows, clear) => (
          <Button size="sm" variant="secondary" onClick={() => { exportRows(rows); clear(); }}>
            <Download /> Export {rows.length} selected
          </Button>
        )}
        renderMobileCard={(c) => (
          <div className="flex flex-col gap-2">
            <div className="flex items-start justify-between gap-2">
              <div className="flex min-w-0 flex-col">
                <span className="font-mono text-[12.5px] font-bold text-brand">{c.caseNumber}</span>
                <span className="font-bold">{c.patient.name}</span>
                <span className="truncate text-xs text-ink-2">{c.restorationType} · {c.clinic.name}</span>
              </div>
              <StatusBadge status={c.status} />
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <SlaCell c={c} />
              <PriorityBadge priority={c.priority} hideNormal />
              {showMoney && <PaymentBadge status={c.paymentStatus} />}
            </div>
          </div>
        )}
        toolbar={
          <ToolbarRow>
            <SearchInput value={f.search} onChange={(v) => setF({ search: v })} placeholder="Case ID, patient, doctor, clinic…" />
            <CollapsibleFilters activeCount={[f.status, f.sla, f.priority, f.caseType, f.technicianId, f.clinicId, f.doctorId, f.paymentStatus, f.from, f.to, f.dueFrom, f.dueTo].filter(Boolean).length}>
            <FilterSelect label="Status" value={f.status} onChange={(v) => setF({ status: v })} options={ALL_STATUSES.map((s) => ({ value: s, label: STATUS_META[s].label }))} />
            <FilterSelect label="Deadline" value={f.sla} onChange={(v) => setF({ sla: v })} options={[{ value: 'on_track', label: 'On track' }, { value: 'at_risk', label: 'At risk' }, { value: 'overdue', label: 'Overdue' }, { value: 'due_today', label: 'Due today' }]} />
            <FilterSelect label="Priority" value={f.priority} onChange={(v) => setF({ priority: v })} options={Object.entries(PRIORITY_META).map(([v, m]) => ({ value: v, label: m.label }))} />
            <FilterSelect label="Case type" value={f.caseType} onChange={(v) => setF({ caseType: v })} options={Object.entries(CASE_TYPE_LABELS).map(([v, l]) => ({ value: v, label: l }))} />
            {techs.data && <FilterSelect label="Technician" value={f.technicianId} onChange={(v) => setF({ technicianId: v })} options={techs.data.data.map((t) => ({ value: t.id, label: t.name }))} />}
            {clinics.data && <FilterSelect label="Clinic" value={f.clinicId} onChange={(v) => setF({ clinicId: v, doctorId: undefined })} options={clinics.data.data.map((k) => ({ value: k.id, label: k.name }))} />}
            {doctors.data && (
              <FilterSelect label="Doctor" value={f.doctorId} onChange={(v) => setF({ doctorId: v })} options={doctors.data.data.filter((d) => !f.clinicId || d.clinicId === f.clinicId).map((d) => ({ value: d.id, label: d.name }))} />
            )}
            {showMoney && <FilterSelect label="Payment" value={f.paymentStatus} onChange={(v) => setF({ paymentStatus: v })} options={Object.entries(PAYMENT_STATUS_META).map(([v, m]) => ({ value: v, label: m.label }))} />}
            <DateFilter label="Received from" value={f.from} onChange={(v) => setF({ from: v })} />
            <DateFilter label="Received to" value={f.to} onChange={(v) => setF({ to: v })} />
            <DateFilter label="Due from" value={f.dueFrom} onChange={(v) => setF({ dueFrom: v })} />
            <DateFilter label="Due to" value={f.dueTo} onChange={(v) => setF({ dueTo: v })} />
            </CollapsibleFilters>
            <ClearFiltersButton show={filtersActive} onClear={resetF} />
          </ToolbarRow>
        }
      />

    </div>
  );
}
