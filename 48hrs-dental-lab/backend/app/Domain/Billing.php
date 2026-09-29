<?php

namespace App\Domain;

/** Pricing, invoice status and payment rules (packages/shared/src/billing.ts). */
final class Billing
{
    /** Units: one per tooth, per denture arch, or one per appliance. */
    public static function unitsFor(string $unitMode, array $teeth, ?string $dentureType): int
    {
        if ($unitMode === 'tooth') {
            return count($teeth);
        }
        if ($unitMode === 'denture') {
            foreach (Catalog::DENTURE_TYPES as $d) {
                if ($d['value'] === $dentureType) {
                    return $d['units'];
                }
            }

            return 1;
        }

        return 1;
    }

    /** @return array{units: int, unitPrice: float|int, subtotal: float, emergencyFee: float|int, total: float} */
    public static function priceCase(string $unitMode, float|int $unitPrice, array $teeth, ?string $dentureType, bool $urgent, float|int $emergencyFeePerUnit): array
    {
        $units = self::unitsFor($unitMode, $teeth, $dentureType);
        $subtotal = Num::round2($unitPrice * $units);
        $emergencyFee = $urgent ? Num::round2($emergencyFeePerUnit * $units) : 0;

        return ['units' => $units, 'unitPrice' => $unitPrice, 'subtotal' => $subtotal, 'emergencyFee' => $emergencyFee, 'total' => Num::round2($subtotal + $emergencyFee)];
    }

    /** unpaid / partial / paid, and overdue when money is still owed after the due date. */
    public static function invoiceStatus(float|int $total, float|int $paid, string|int|\DateTimeInterface $dueDate, int $now): string
    {
        $remaining = Num::round2($total - $paid);
        if ($remaining <= 0) {
            return 'paid';
        }
        if (Dates::ms($dueDate) < $now) {
            return 'overdue';
        }

        return $paid > 0 ? 'partial' : 'unpaid';
    }

    /** Validates a payment against what is still owed; an error message or null. */
    public static function validatePaymentAmount(mixed $amount, float|int $remaining): ?string
    {
        if (! is_int($amount) && ! is_float($amount) || ! is_finite((float) $amount) || $amount <= 0) {
            return 'Enter an amount greater than zero.';
        }
        if (Num::round2($amount) > Num::round2($remaining)) {
            return 'Amount cannot exceed the remaining balance of '.Num::fixed2($remaining).'.';
        }

        return null;
    }

    /** Non-cash payments must carry a transaction reference. */
    public static function referenceRequired(?string $method): bool
    {
        return $method !== null && $method !== '' && $method !== 'cash';
    }
}
