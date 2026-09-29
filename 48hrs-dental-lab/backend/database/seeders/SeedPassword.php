<?php

namespace Database\Seeders;

use RuntimeException;

/** Seed passwords follow the account password policy and can never be a template placeholder. */
final class SeedPassword
{
    public static function require(string $name): string
    {
        $value = (string) env($name, '');
        if (strlen($value) < 8 || ! preg_match('/[A-Za-z]/', $value) || ! preg_match('/\d/', $value)) {
            throw new RuntimeException("{$name} must be set to a password of at least 8 characters with letters and numbers.");
        }
        if (stripos($value, 'CHANGE_ME') !== false) {
            throw new RuntimeException("{$name} still holds the template placeholder; choose a real password.");
        }

        return $value;
    }
}
