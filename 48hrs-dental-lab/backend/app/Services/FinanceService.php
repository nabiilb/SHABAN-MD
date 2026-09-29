<?php

namespace App\Services;

use App\Domain\Dates;
use App\Domain\LabMessages;
use App\Domain\Num;
use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Activity;
use App\Support\LabSettings;
use App\Support\Present;
use App\Support\Query;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Invoices and payments. Remaining = Total − Paid is derived in one place (Present::invoiceFigures); payments are only written by the ledger. */
class FinanceService
{
    private const LIST_WITH = ['dentalCase:id,case_number', 'patient:id,name', 'doctor:id,name', 'clinic:id,name'];

    private function statusWhere(Builder $q, string $status, int $now): void
    {
        $at = Dates::fromMs($now);
        match ($status) {
            'paid' => $q->whereColumn('invoices.amount_paid', '>=', 'invoices.total'),
            'overdue' => $q->whereColumn('invoices.amount_paid', '<', 'invoices.total')->where('invoices.due_date', '<', $at),
            'partial' => $q->where('invoices.amount_paid', '>', 0)->whereColumn('invoices.amount_paid', '<', 'invoices.total')->where('invoices.due_date', '>=', $at),
            'unpaid' => $q->where('invoices.amount_paid', 0)->where('invoices.total', '>', 0)->where('invoices.due_date', '>=', $at),
        };
    }

    private function dayRange(Builder $q, string $column, ?string $from, ?string $to): void
    {
        if ($from) {
            $q->where($column, '>=', Dates::dayStartUtc($from));
        }
        if ($to) {
            $q->where($column, '<', Dates::dayEndUtc($to));
        }
    }

    public function detail(string $id, ?int $now = null): array
    {
        $now ??= Dates::nowMs();
        $inv = Invoice::with(['dentalCase:id,case_number,restoration_type,teeth,units,unit_price', 'patient:id,name', 'doctor:id,name', 'clinic:id,name', 'payments' => fn ($q) => $q->orderByDesc('paid_at'), 'payments.receivedBy:id,name'])->findOrFail($id);
        $c = $inv->dentalCase;
        $units = $c->units ?: 1;
        $teeth = $c->teeth ?? [];
        $lineItems = [[
            'description' => $c->restoration_type.($teeth ? ' — teeth '.implode(', ', $teeth) : ''),
            'quantity' => $units,
            'unitPrice' => Present::money($c->unit_price),
            'amount' => Present::money($inv->subtotal),
        ]];
        if ($inv->emergency_fee > 0) {
            $lineItems[] = ['description' => 'Emergency turnaround', 'quantity' => $units, 'unitPrice' => Num::clean(Num::round2($inv->emergency_fee / $units)), 'amount' => Present::money($inv->emergency_fee)];
        }

        return [...Present::invoiceListItem($inv, $now), 'payments' => $inv->payments->map(fn ($p) => Present::payment($p))->all(), 'lineItems' => $lineItems];
    }

    public function invoices(User $u, array $q, array $page): array
    {
        $now = Dates::nowMs();
        $query = Invoice::query();
        Scope::whereClinic($query, $u, 'invoices.clinic_id');
        if ($q['clinicId']) {
            $query->where('invoices.clinic_id', $q['clinicId']);
        }
        if ($q['doctorId']) {
            $query->where('invoices.doctor_id', $q['doctorId']);
        }
        $this->dayRange($query, 'invoices.issued_at', $q['from'], $q['to']);
        if ($q['status']) {
            $this->statusWhere($query, $q['status'], $now);
        }
        if ($q['search']) {
            $like = Query::like($q['search']);
            $query->where(fn ($w) => $w->where('invoices.invoice_number', 'like', $like)
                ->orWhereHas('dentalCase', fn ($c) => $c->where('case_number', 'like', $like))
                ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', $like))
                ->orWhereHas('clinic', fn ($k) => $k->where('name', 'like', $like))
                ->orWhereHas('doctor', fn ($d) => $d->where('name', 'like', $like)));
        }
        $total = (clone $query)->count();
        $p = Query::paginate($page, $total);
        $dir = $q['dir'];
        $query->select('invoices.*');
        $at = Dates::fromMs($now)->format('Y-m-d H:i:s.v');
        match ($q['sort'] ?? 'issuedAt') {
            // Balance and status are derived from total / amount paid / due date — ordered in SQL.
            'remaining' => $query->orderByRaw('GREATEST(invoices.total - invoices.amount_paid, 0) '.$dir)->orderByDesc('invoices.issued_at')->orderBy('invoices.id'),
            'status' => $query->orderByRaw("CASE WHEN invoices.amount_paid >= invoices.total THEN 'paid' WHEN invoices.due_date < ? THEN 'overdue' WHEN invoices.amount_paid > 0 THEN 'partial' ELSE 'unpaid' END {$dir}", [$at])->orderByDesc('invoices.issued_at')->orderBy('invoices.id'),
            'invoiceNumber' => $query->orderBy('invoices.invoice_number', $dir),
            'dueDate' => $query->orderBy('invoices.due_date', $dir)->orderBy('invoices.invoice_number', $dir),
            'total' => $query->orderBy('invoices.total', $dir)->orderBy('invoices.invoice_number', $dir),
            'paid' => $query->orderBy('invoices.amount_paid', $dir)->orderBy('invoices.invoice_number', $dir),
            'clinic' => $query->join('clinics as sk', 'sk.id', '=', 'invoices.clinic_id')->orderBy('sk.name', $dir)->orderBy('invoices.invoice_number', $dir),
            default => $query->orderBy('invoices.issued_at', $dir)->orderBy('invoices.invoice_number', $dir),
        };
        $rows = $query->with(self::LIST_WITH)->offset($p['offset'])->limit($p['limit'])->get();

        return ['data' => $rows->map(fn ($r) => Present::invoiceListItem($r, $now))->all(), 'meta' => $p['meta']];
    }

    public function invoice(User $u, string $idOrNumber): array
    {
        $q = Invoice::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('invoice_number', $idOrNumber));
        Scope::whereClinic($q, $u, 'invoices.clinic_id');
        $id = $q->value('id') ?? throw ApiException::notFound();

        return $this->detail($id);
    }

    /** Issues the invoice for a received case that has none (acceptance normally does this). */
    public function createInvoice(User $u, string $caseId): array
    {
        $c = CaseQuery::find($u, $caseId)->with('invoice:id,case_id')->first();
        if (! $c) {
            throw ApiException::validation(['caseId' => ['Choose a valid case.']]);
        }
        if ($c->invoice) {
            throw ApiException::validation(['caseId' => ['This case already has an invoice.']]);
        }
        if (! $c->received_at || in_array($c->status, ['cancelled', 'rejected'], true)) {
            throw ApiException::validation(['caseId' => ['Only cases received by the lab can be invoiced.']]);
        }
        $settings = LabSettings::get();
        $inv = DB::transaction(function () use ($u, $c, $settings) {
            $inv = InvoiceIssuer::issue($c, $settings);
            Activity::log($u, 'invoice.create', "Issued {$inv->invoice_number} for {$c->case_number}", 'invoice', $inv->id, $inv->invoice_number);

            return $inv;
        });

        return $this->detail($inv->id);
    }

    public function recordPayment(User $u, array $b): array
    {
        $q = Invoice::whereKey($b['invoiceId']);
        Scope::whereClinic($q, $u, 'invoices.clinic_id');
        $inv = $q->first(['id', 'invoice_number', 'case_id']) ?? throw ApiException::notFound();
        DB::transaction(function () use ($u, $inv, $b) {
            $payment = PaymentLedger::record($inv->id, ['amount' => $b['amount'], 'method' => $b['method'], 'reference' => $b['reference'], 'notes' => $b['notes'], 'paidAt' => $b['paidAt'] ?? null], $u->id);
            Activity::log($u, 'payment.record', 'Recorded '.Num::fixed2($payment->amount)." on {$inv->invoice_number}", 'invoice', $inv->id, $inv->invoice_number);
            Notifier::send(Notifier::recipients(LabMessages::RECIPIENTS['finance']), LabMessages::paymentReceived($payment->amount, $inv->invoice_number), $inv->case_id, $u->id);
        });

        return $this->detail($inv->id);
    }

    public function payments(User $u, array $q, array $page): array
    {
        $query = Payment::query()->join('invoices', 'invoices.id', '=', 'payments.invoice_id');
        Scope::whereClinic($query, $u, 'invoices.clinic_id');
        if ($q['method']) {
            $query->where('payments.method', $q['method']);
        }
        if ($q['clinicId']) {
            $query->where('invoices.clinic_id', $q['clinicId']);
        }
        $this->dayRange($query, 'payments.paid_at', $q['from'], $q['to']);
        if ($q['search']) {
            $like = Query::like($q['search']);
            // payments.reference has a binary collation (exact duplicate check): search it case-insensitively.
            $query->where(fn ($w) => $w->whereRaw('LOWER(payments.reference) LIKE ?', [mb_strtolower($like)])->orWhere('invoices.invoice_number', 'like', $like)
                ->orWhereExists(fn ($e) => $e->from('cases')->whereColumn('cases.id', 'invoices.case_id')->where('cases.case_number', 'like', $like))
                ->orWhereExists(fn ($e) => $e->from('clinics')->whereColumn('clinics.id', 'invoices.clinic_id')->where('clinics.name', 'like', $like))
                ->orWhereExists(fn ($e) => $e->from('users')->whereColumn('users.id', 'payments.received_by_id')->where('users.name', 'like', $like)));
        }
        $total = (clone $query)->count();
        $p = Query::paginate($page, $total);
        $dir = $q['dir'];
        $query->select('payments.*');
        match ($q['sort'] ?? 'paidAt') {
            'amount' => $query->orderBy('payments.amount', $dir)->orderByDesc('payments.paid_at'),
            'method' => $query->orderBy('payments.method', $dir)->orderByDesc('payments.paid_at'),
            'invoiceNumber' => $query->orderBy('invoices.invoice_number', $dir)->orderByDesc('payments.paid_at'),
            'clinic' => $query->join('clinics as sk', 'sk.id', '=', 'invoices.clinic_id')->orderBy('sk.name', $dir)->orderByDesc('payments.paid_at'),
            default => $query->orderBy('payments.paid_at', $dir)->orderBy('payments.id', $dir),
        };
        $rows = $query->with(['receivedBy:id,name', 'invoice:id,invoice_number,case_id,clinic_id', 'invoice.dentalCase:id,case_number', 'invoice.clinic:id,name'])->offset($p['offset'])->limit($p['limit'])->get();

        return ['data' => $rows->map(fn ($r) => [...Present::payment($r), 'invoiceNumber' => $r->invoice->invoice_number, 'caseId' => $r->invoice->case_id, 'caseNumber' => $r->invoice->dentalCase->case_number, 'clinic' => Present::ref($r->invoice->clinic)])->all(), 'meta' => $p['meta']];
    }
}
