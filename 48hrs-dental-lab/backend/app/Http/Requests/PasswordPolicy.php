<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The account password policy (shared passwordSchema). Implicit: runs on empty
 * values too, so a blank password reports every rule it breaks, like the web app.
 */
class PasswordPolicy implements ValidationRule
{
    /** Required passwords: run on empty values too. Optional ones (user edit): only when given. */
    public function __construct(public bool $implicit = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = is_string($value) ? $value : '';
        if (mb_strlen($value) < 8) {
            $fail('Use at least 8 characters.');
        }
        if (! preg_match('/[A-Za-z]/', $value)) {
            $fail('Include at least one letter.');
        }
        if (! preg_match('/\d/', $value)) {
            $fail('Include at least one number.');
        }
    }
}
