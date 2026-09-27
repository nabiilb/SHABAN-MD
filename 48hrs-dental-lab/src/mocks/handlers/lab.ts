import { PERMISSIONS } from '@/lib/permissions';
import type { DeliveryListItem, QualityCheckListItem } from '@/types/api';
import { authenticate, authorize } from '../auth-context';
import { visibleCases } from '../domain';
import { includesText, paginate, qStr, route, sortItems } from '../router';

route('GET', '/quality-checks', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.QC_VIEW);
  const q = raw.query;
  const cases = new Map(visibleCases(ctx).map((c) => [c.id, c]));
  const items: QualityCheckListItem[] = ctx.db.qualityChecks
    .filter((r) => cases.has(r.caseId))
    .map((r) => {
      const c = cases.get(r.caseId)!;
      return {
        ...r,
        caseNumber: c.caseNumber,
        caseStatus: c.status,
        patientName: ctx.db.patients.find((p) => p.id === c.patientId)?.name ?? '—',
        clinicName: ctx.db.clinics.find((k) => k.id === c.clinicId)?.name ?? '—',
        technicianName: ctx.db.technicians.find((t) => t.id === c.technicianId)?.name ?? null,
      };
    })
    .filter((r) => !qStr(q, 'result') || r.result === qStr(q, 'result'))
    .filter((r) => !qStr(q, 'technicianId') || cases.get(r.caseId)?.technicianId === qStr(q, 'technicianId'))
    .filter((r) => includesText([r.caseNumber, r.patientName, r.clinicName, r.checkedByName, r.notes], qStr(q, 'search')));
  return paginate(sortItems(items, q, { checkedAt: (r) => r.checkedAt, caseNumber: (r) => r.caseNumber, result: (r) => r.result }, 'checkedAt'), q);
});

route('GET', '/deliveries', (raw) => {
  const ctx = authenticate(raw);
  authorize(ctx, PERMISSIONS.DELIVERY_VIEW);
  const q = raw.query;
  const cases = new Map(visibleCases(ctx).map((c) => [c.id, c]));
  const items: DeliveryListItem[] = ctx.db.deliveries
    .filter((d) => cases.has(d.caseId))
    .map((d) => {
      const c = cases.get(d.caseId)!;
      return {
        ...d,
        caseNumber: c.caseNumber,
        patientName: ctx.db.patients.find((p) => p.id === c.patientId)?.name ?? '—',
        clinicName: ctx.db.clinics.find((k) => k.id === c.clinicId)?.name ?? '—',
      };
    })
    .filter((d) => !qStr(q, 'status') || d.status === qStr(q, 'status'))
    .filter((d) => !qStr(q, 'method') || d.method === qStr(q, 'method'))
    .filter((d) => includesText([d.caseNumber, d.patientName, d.clinicName, d.receivedBy, d.courierName], qStr(q, 'search')));
  return paginate(sortItems(items, q, { deliveredAt: (d) => d.deliveredAt ?? d.dispatchedAt ?? d.createdAt, caseNumber: (d) => d.caseNumber, clinic: (d) => d.clinicName }, 'deliveredAt'), q);
});
