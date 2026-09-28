<?php

namespace App\Support;

/**
 * The one way this application reads a phone number.
 *
 * A phone number is how the school identifies a student — the register is kept
 * by it, the import rejects a row without one and matches existing students on
 * it, and the admin screen refuses a second active student who has one already.
 * That only works if "0611000111", "+252 611 000 111" and "252611000111" are
 * recognised as the same person, so every one of those places asks this class
 * rather than comparing the characters somebody happened to type.
 *
 * Normalising is not rewriting: what the admin typed is still what is stored
 * and shown in `students.phone`. This produces the *key* the number is compared
 * by, which is kept beside it.
 */
class PhoneNumber
{
    /**
     * Somali mobile numbers are nine digits; the register writes them bare, the
     * admin screen usually writes them with the country code, and both mean the
     * same number. Everything comes back in the same +252 form.
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) (is_float($value) ? number_format($value, 0, '', '') : $value));

        if ($digits === '') {
            return null;
        }

        $digits = match (true) {
            str_starts_with($digits, '252') => substr($digits, 3),
            str_starts_with($digits, '0') => ltrim($digits, '0'),
            default => $digits,
        };

        return $digits === '' ? null : '+252'.$digits;
    }

    /** Whether two numbers are the same number, however each was written. */
    public static function same(mixed $a, mixed $b): bool
    {
        $left = static::normalize($a);

        return $left !== null && $left === static::normalize($b);
    }
}
