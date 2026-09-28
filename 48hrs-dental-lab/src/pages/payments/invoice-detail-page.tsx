import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Printer, Wallet } from 'lucide-react';
import logo from '@/assets/logo-48hrs.png';
import { PAYMENT_METHOD_LABELS } from '@/lib/constants';
import { PERMISSIONS } from '@/lib/permissions';
import { useInvoice } from '@/hooks/api/use-finance';
import { useSettings } from '@/hooks/api/use-admin';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';
import { PaymentBadge } from '@/components/cases/badges';
import { RecordPaymentDialog } from '@/components/payments/record-payment-dialog';
import { Button } from '@/components/ui/button';
import { QueryError } from '@/components/ui/query-error';
import { PageToolbar } from '@/components/ui/page-toolbar';
import { SimpleTable } from '@/components/ui/simple-table';
import { Card, CardBody, CardHeader, Field } from '@/components/ui/card';
import { PageLoader, ProgressBar } from '@/components/ui/feedback';

export default function InvoiceDetailPage() {
  const { id } = useParams();
  const { data: inv, isLoading, error, refetch } = useInvoice(id);
  const settings = useSettings();
  const { can } = useAuth();
  const [paying, setPaying] = useState(false);
  usePageTitle(inv?.invoiceNumber ?? 'Invoice', inv ? `${inv.clinic.name} · case ${inv.caseNumber}` : undefined);

  if (isLoading) return <PageLoader />;
  if (error || !inv) return <QueryError error={error} onRetry={() => void refetch()} />;
  const lab = settings.data;

  return (
    <div className="flex flex-col gap-4">
      <PageToolbar
        className="no-print"
        actions={
          <>
            <Button variant="outline" onClick={() => window.print()}><Printer /> Print</Button>
            {can(PERMISSIONS.PAYMENTS_RECORD) && inv.remaining > 0 && <Button onClick={() => setPaying(true)}><Wallet /> Record payment</Button>}
          </>
        }
      />

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

          <SimpleTable
            caption="Invoice lines"
            minWidth={480}
            rows={inv.lineItems}
            getKey={(_, i) => String(i)}
            columns={[
              { header: 'Description', cell: (l) => l.description },
              { header: 'Qty', align: 'right', className: 'font-mono', cell: (l) => l.quantity },
              { header: 'Unit price', align: 'right', className: 'font-mono', cell: (l) => formatMoney(l.unitPrice) },
              { header: 'Amount', align: 'right', className: 'font-mono font-bold', cell: (l) => formatMoney(l.amount) },
            ]}
          />

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
          <SimpleTable
            caption="Payment history"
            minWidth={560}
            rows={inv.payments}
            getKey={(p) => p.id}
            empty="No payments recorded yet."
            columns={[
              { header: 'Date', className: 'whitespace-nowrap', cell: (p) => formatDateTime(p.paidAt) },
              { header: 'Method', cell: (p) => PAYMENT_METHOD_LABELS[p.method] },
              { header: 'Reference', className: 'font-mono text-[12.5px]', cell: (p) => p.reference || '—' },
              { header: 'Received by', cell: (p) => p.receivedByName },
              { header: 'Amount', align: 'right', className: 'font-mono font-bold', cell: (p) => formatMoney(p.amount) },
            ]}
          />
        </CardBody>
      </Card>

      <RecordPaymentDialog open={paying} onOpenChange={setPaying} invoiceId={inv.id} invoiceNumber={inv.invoiceNumber} remaining={inv.remaining} />
    </div>
  );
}
