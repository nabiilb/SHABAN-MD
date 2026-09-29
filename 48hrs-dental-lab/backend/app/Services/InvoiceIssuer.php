<?php

namespace App\Services;

use App\Domain\Dates;
use App\Domain\Num;
use App\Models\DentalCase;
use App\Models\Invoice;
use App\Support\Numbers;
use App\Support\Sequences;

/** Issues a case's invoice when the lab receives it: total = the case total, due after the lab's payment terms. */
final class InvoiceIssuer
{
    public static function issue(DentalCase $c, array $settings, ?int $now = null): Invoice
    {
        $now ??= Dates::nowMs();

        return Invoice::create([
            'invoice_number' => Numbers::invoiceNumber(Dates::labYear($now), Sequences::next(Sequences::INVOICE)),
            'case_id' => $c->id,
            'patient_id' => $c->patient_id,
            'doctor_id' => $c->doctor_id,
            'clinic_id' => $c->clinic_id,
            'subtotal' => Num::round2($c->total - $c->emergency_fee),
            'emergency_fee' => $c->emergency_fee,
            'discount' => 0,
            'total' => $c->total,
            'amount_paid' => 0,
            'issued_at' => Dates::fromMs($now),
            'due_date' => Dates::fromMs($now + (int) ($settings['invoiceDueDays'] * Dates::DAY_MS)),
        ]);
    }
}
