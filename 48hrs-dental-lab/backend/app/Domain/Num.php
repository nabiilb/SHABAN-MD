<?php

namespace App\Domain;

/** JavaScript-compatible number helpers, so PHP figures match the web app's to the cent. */
final class Num
{
    /** Math.round(): halves round towards +∞ (PHP's round() goes away from zero). */
    public static function jsRound(float $x): float
    {
        return floor($x + 0.5);
    }

    public static function round2(float|int $n): float
    {
        return self::jsRound($n * 100) / 100;
    }

    public static function round1(float|int $n): float
    {
        return self::jsRound($n * 10) / 10;
    }

    /** Number.prototype.toFixed(2) — formats the exact binary value, like printf. */
    public static function fixed2(float|int $n): string
    {
        return sprintf('%.2f', $n);
    }

    /** round1 of the mean, or null for no samples (shared analytics `average`). */
    public static function average(array $xs): ?float
    {
        return $xs ? self::round1(array_sum($xs) / count($xs)) : null;
    }

    /** A whole float as int (JSON prints 3 instead of 3.0, like JavaScript). */
    public static function clean(float|int|null $n): float|int|null
    {
        if ($n === null) {
            return null;
        }

        return is_float($n) && floor($n) === $n && abs($n) < PHP_INT_MAX ? (int) $n : $n;
    }
}
