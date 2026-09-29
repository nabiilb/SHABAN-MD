<?php

namespace App\Support;

/** Human-readable reference builders (packages/shared/src/case-keys.ts). */
final class Numbers
{
    public static function caseNumber(int $year, int $seq): string
    {
        return sprintf('DL-%d-%05d', $year, $seq);
    }

    public static function invoiceNumber(int $year, int $seq): string
    {
        return sprintf('INV-%d-%05d', $year, $seq);
    }

    public static function patientCode(int $seq): string
    {
        return "PT-{$seq}";
    }
}
