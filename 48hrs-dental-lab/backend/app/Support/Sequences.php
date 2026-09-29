<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Counters for case, invoice and patient numbers. Must run inside the caller's
 * transaction: the row lock (SELECT … FOR UPDATE) makes concurrent requests take
 * distinct numbers; the row is created on first use.
 */
final class Sequences
{
    public const CASE = 'case_number';

    public const INVOICE = 'invoice_number';

    public const PATIENT = 'patient_code';

    public static function next(string $name): int
    {
        $row = DB::table('sequences')->where('name', $name)->lockForUpdate()->first();
        if (! $row) {
            DB::table('sequences')->insertOrIgnore(['name' => $name, 'value' => 0]);
            $row = DB::table('sequences')->where('name', $name)->lockForUpdate()->first();
        }
        $value = $row->value + 1;
        DB::table('sequences')->where('name', $name)->update(['value' => $value]);

        return $value;
    }
}
