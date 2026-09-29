<?php

namespace App\Services;

use App\Domain\Billing;
use App\Domain\Dates;
use App\Domain\Num;
use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Present;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only code path that records money. Runs inside the caller's transaction:
 * locks the invoice row (SELECT … FOR UPDATE) so two cashiers cannot both take
 * the last balance; validates with the shared rules (remaining = total − paid,
 * amount > 0 and ≤ remaining, reference for non-cash and never reused); keeps
 * invoices.amount_paid equal to the sum of its payments. A reused transaction
 * reference is refused by the check and, if two requests race, by the unique
 * index on payments.reference — either way the whole transaction rolls back.
 */
final class PaymentLedger
{
    /** @param array{amount: mixed, method: string, reference?: ?string, notes?: ?string, paidAt?: ?string} $input */
    public static function record(string $invoiceId, array $input, string $receivedById, string $fieldPrefix = '', ?int $now = null): Payment
    {
        $now ??= Dates::nowMs();
        /** @var Invoice|null $invoice */
        $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->first();
        if (! $invoice) {
            throw ApiException::notFound();
        }
        $remaining = Present::invoiceFigures($invoice, $now)['remaining'];
        $errors = [];
        if ($e = Billing::validatePaymentAmount($input['amount'], $remaining)) {
            $errors["{$fieldPrefix}amount"] = [$e];
        }
        $reference = trim((string) ($input['reference'] ?? ''));
        if (Billing::referenceRequired($input['method']) && $reference === '') {
            $errors["{$fieldPrefix}reference"] = ['Enter the transaction reference.'];
        }
        $paidAt = ! empty($input['paidAt']) ? Dates::ms($input['paidAt']) : $now;
        if ($paidAt > $now + 60_000) {
            $errors["{$fieldPrefix}paidAt"] = ['Payment date cannot be in the future.'];
        }
        if ($reference !== '' && ! isset($errors["{$fieldPrefix}reference"]) && ($dup = self::duplicateOf($reference))) {
            $errors["{$fieldPrefix}reference"] = [$dup];
        }
        ApiException::throwIf($errors);

        $amount = Num::round2($input['amount']);
        try {
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => $input['method'],
                'reference' => $reference !== '' ? $reference : null,
                'notes' => trim((string) ($input['notes'] ?? '')),
                'received_by_id' => $receivedById,
                'paid_at' => Dates::fromMs($paidAt),
            ]);
        } catch (QueryException $e) {
            // The unique index lost the race for us: another request recorded this reference first.
            if ($reference !== '' && self::isDuplicateKey($e)) {
                throw ApiException::validation(["{$fieldPrefix}reference" => [self::duplicateOf($reference, locking: true) ?? 'This reference is already recorded.']]);
            }
            throw $e;
        }
        DB::table('invoices')->where('id', $invoice->id)->update(['amount_paid' => DB::raw('amount_paid + '.number_format($amount, 2, '.', '')), 'updated_at' => Dates::fromMs($now)->format('Y-m-d H:i:s.v')]);

        return $payment;
    }

    /** "This reference is already recorded on INV-…" when the reference exists (exact match). */
    public static function duplicateOf(string $reference, bool $locking = false): ?string
    {
        // A locking read sees the latest committed row (a plain read would use this transaction's snapshot).
        $q = DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->where('payments.reference', $reference);
        $number = ($locking ? $q->sharedLock() : $q)->value('invoices.invoice_number');

        return $number ? "This reference is already recorded on {$number}." : null;
    }

    public static function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
