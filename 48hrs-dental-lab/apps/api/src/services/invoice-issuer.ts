import { round2 } from '@48hrs/shared/billing';
import { invoiceNumber } from '@48hrs/shared/case-keys';
import { HOUR_MS } from '@48hrs/shared/sla';
import type { LabSettings } from '@48hrs/shared/types';
import type { Tx } from '../lib/prisma.ts';
import { nextSequence, SEQUENCES } from '../repositories/sequence-repository.ts';
import { labYear } from '../utils/dates.ts';

type CaseMoney = { id: string; patientId: string; doctorId: string; clinicId: string; total: { toNumber(): number }; emergencyFee: { toNumber(): number } };

/** Issues a case's invoice when the lab receives it: total = the case total, due after the lab's payment terms. */
export async function issueInvoice(tx: Tx, c: CaseMoney, settings: LabSettings, now = Date.now()) {
  const total = c.total.toNumber();
  const emergencyFee = c.emergencyFee.toNumber();
  return tx.invoice.create({
    data: {
      invoiceNumber: invoiceNumber(labYear(now), await nextSequence(tx, SEQUENCES.invoice)),
      caseId: c.id,
      patientId: c.patientId,
      doctorId: c.doctorId,
      clinicId: c.clinicId,
      subtotal: round2(total - emergencyFee),
      emergencyFee,
      total,
      issuedAt: new Date(now),
      dueDate: new Date(now + settings.invoiceDueDays * 24 * HOUR_MS),
    },
  });
}
