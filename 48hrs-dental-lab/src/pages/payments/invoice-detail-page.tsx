import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Printer, Wallet } from 'lucide-react';
import logo from '@/assets/logo-48hrs.png';
import { PAYMENT_METHOD_LABELS } from '@/lib/constants';
import { PERMISSIONS } from '@/lib/permissions';
import { useInvoice } from '@/hooks/api/use-finance';
import { useSettings } from '@/hooks/api/use-admin';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { errorMessage } from '@/services/api/errors';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';
import { PaymentBadge } from '@/components/cases/badges';
import { RecordPaymentDialog } from '@/components/payments/record-payment-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardBody, CardHeader, Field } from '@/components/ui/card';
import { ErrorState, PageLoader, ProgressBar } from '@/components/ui/feedback';

export default function InvoiceDetailPage() {
  const { id } = useParams();
  const { data: inv, isLoading, error, refetch } = useInvoice(id);
  const settings = useSettings();
  const { can } = useAuth();
  const [paying, setPaying] = useState(false);
  usePageTitle(inv?.invoiceNumber ?? 'Invoice', inv ? `${inv.clinic.name} · case ${inv.caseNumber}` : undefined);

  if (isLoading) return <PageLoader />;
  if (error || !inv) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />;
  const lab = settings.data;

  return (
    <div className="flex flex-col gap-4">
      <div className="no-print flex flex-wrap items-center justify-between gap-2">
        <Button asChild variant="ghost" size="sm"><Link to="/invoices"><ArrowLeft /> Invoices</Link></Button>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => window.print()}><Printer /> Print</Button>
          {can(PERMISSIONS.PAYMENTS_RECORD) && inv.remaining > 0 && <Button onClick={() => setPaying(true)}><Wallet /> Record payment</Button>}
        </div>
      </div>

      <Card>
        <CardBody className="flex flex-col gap-6">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex flex-col gap-2">
              <img src={logo} alt="48HRS Dental Lab" className="h-14 w-auto self-start" />
              {lab && <p className="text-xs leading-relaxed text-ink-2">{lab.labName}<br />{lab.address}<br />{lab.phone} · {lab.email}</p>}
            </div>
            <div className="flex flex-col items-end gap-1.5 text-right">
              <span className="eyebrow">Invoice</span>
              <span className="font-mono text-xl font-bold text-ink">{inv.invoiceNumber}</span>
              <PaymentBadge status={inv.status} />
            </div>
          </div>

          <dl className="grid grid-cols-2 gap-4 rounded-md bg-gray-50 p-4 sm:grid-cols-4">
            <Field label="Bill to">{inv.clinic.name}</Field>
            <Field label="Doctor">{inv.doctor.name}</Field>
            <Field label="Patient">{inv.patient.name}</Field>
            <Field label="Case" mono><Link to={`/cases/${inv.caseId}`} className="hover:underline">{inv.caseNumber}</Link></Field>
            <Field label="Issued">{formatDate(inv.issuedAt)}</Field>
            <Field label="Due date">{formatDate(inv.dueDate)}</Field>
          </dl>

          <div className="overflow-x-auto">
            <table className="w-full min-w-[480px] text-sm">
              <thead>
                <tr className="border-b border-line text-left text-[11px] font-bold tracking-[.12em] text-ink-3 uppercase">
                  <th className="py-2.5 pr-4">Description</th>
                  <th className="py-2.5 pr-4 text-right">Qty</th>
                  <th className="py-2.5 pr-4 text-right">Unit price</th>
                  <th className="py-2.5 text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                {inv.lineItems.map((l, i) => (
                  <tr key={i} className="border-b border-line">
                    <td className="py-3 pr-4">{l.description}</td>
                    <td className="py-3 pr-4 text-right font-mono">{l.quantity}</td>
                    <td className="py-3 pr-4 text-right font-mono">{formatMoney(l.unitPrice)}</td>
                    <td className="py-3 text-right font-mono font-bold">{formatMoney(l.amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="ml-auto flex w-full max-w-xs flex-col gap-2 text-sm">
            <div className="flex justify-between"><span className="text-ink-2">Total</span><span className="font-mono font-bold">{formatMoney(inv.total)}</span></div>
            <div className="flex justify-between"><span className="text-ink-2">Paid</span><span className="font-mono text-success">−{formatMoney(inv.paid)}</span></div>
            <div className="flex items-baseline justify-between border-t border-line pt-2"><span className="text-xs font-extrabold tracking-[.12em] text-ink-2">REMAINING</span><span className="font-mono text-2xl font-bold text-brand">{formatMoney(inv.remaining)}</span></div>
            <ProgressBar value={inv.total ? inv.paid / inv.total : 0} tone={inv.status === 'paid' ? 'success' : inv.status === 'overdue' ? 'danger' : 'warning'} label="Paid share" />
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Payment history" />
        <CardBody>
          {inv.payments.length === 0 ? (
            <p className="text-[13px] text-ink-3">No payments recorded yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-sm">
                <thead>
                  <tr className="border-b border-line text-left text-[11px] font-bold tracking-[.12em] text-ink-3 uppercase">
                    <th className="py-2.5 pr-4">Date</th><th className="py-2.5 pr-4">Method</th><th className="py-2.5 pr-4">Reference</th><th className="py-2.5 pr-4">Received by</th><th className="py-2.5 text-right">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {inv.payments.map((p) => (
                    <tr key={p.id} className="border-b border-line">
                      <td className="py-3 pr-4 whitespace-nowrap">{formatDateTime(p.paidAt)}</td>
                      <td className="py-3 pr-4">{PAYMENT_METHOD_LABELS[p.method]}</td>
                      <td className="py-3 pr-4 font-mono text-[12.5px]">{p.reference || '—'}</td>
                      <td className="py-3 pr-4">{p.receivedByName}</td>
                      <td className="py-3 text-right font-mono font-bold">{formatMoney(p.amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <RecordPaymentDialog open={paying} onOpenChange={setPaying} invoiceId={inv.id} invoiceNumber={inv.invoiceNumber} remaining={inv.remaining} />
    </div>
  );
}
