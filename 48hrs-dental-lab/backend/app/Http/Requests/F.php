<?php

namespace App\Http\Requests;

use App\Domain\Dates;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Field building blocks with the exact messages of packages/shared/src/schemas.ts,
 * so a 422 from Laravel reads the same as the web app's own form validation.
 */
final class F
{
    /** Zod's email pattern. */
    public const EMAIL = "/^(?!\\.)(?!.*\\.\\.)([A-Z0-9_'+\\-\\.]*)[A-Z0-9_+-]@([A-Z0-9][A-Z0-9\\-]*\\.)+[A-Z]{2,}$/i";

    public const PHONE = '/^[+\d][\d\s()-]{5,}$/';

    /** Zod's datetime({ offset: true }): date with leap years, time with optional seconds/fraction, Z or ±hh[:]mm. */
    public const DATETIME = '/^((\d\d[2468][048]|\d\d[13579][26]|\d\d0[48]|[02468][048]00|[13579][26]00)-02-29|\d{4}-((0[13578]|1[02])-(0[1-9]|[12]\d|3[01])|(0[469]|11)-(0[1-9]|[12]\d|30)|(02)-(0[1-9]|1\d|2[0-8])))T([01]\d|2[0-3]):[0-5]\d(:[0-5]\d(\.\d+)?)?(Z|([+-]\d{2}:?\d{2}))$/';

    public const DAY = '/^\d{4}-\d{2}-\d{2}$/';

    private static function trim(): Closure
    {
        return fn ($v) => is_string($v) ? trim($v) : $v;
    }

    /** Required, trimmed text: "<Label> is required." whether missing, null, not a string or blank. */
    public static function required(string $label, int $max = 120): Field
    {
        return (new Field)->pre(self::trim())
            ->rules('required', 'string', "max:{$max}")
            ->messages(['required' => "{$label} is required.", 'string' => "{$label} is required.", 'max' => "{$label} must be {$max} characters or fewer."]);
    }

    public static function id(string $label): Field
    {
        return self::required($label, 64);
    }

    /** Optional id: blank → null. */
    public static function optionalId(): Field
    {
        return (new Field)->pre(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v)
            ->rules('nullable', 'string', 'max:64')->messages(['string' => 'Expected string.', 'max' => 'Keep this under 64 characters.'])->default(null);
    }

    /** Optional trimmed text with a default ('' unless given). */
    public static function text(int $max = 500, mixed $default = ''): Field
    {
        return (new Field)->pre(self::trim())
            ->rules('nullable', 'string', "max:{$max}")
            ->messages(['string' => 'Expected string.', 'max' => "Keep this under {$max} characters."])
            ->default($default);
    }

    public static function email(): Field
    {
        return (new Field)->pre(fn ($v) => is_string($v) ? strtolower(trim($v)) : $v)
            ->rules('required', 'string', 'regex:'.self::EMAIL)
            ->messages(['required' => 'Email is required.', 'string' => 'Email is required.', 'regex' => 'Enter a valid email address.']);
    }

    public static function optionalEmail(): Field
    {
        return (new Field)->pre(fn ($v) => is_string($v) ? strtolower(trim($v)) : $v)
            ->rules('nullable', 'string', 'regex:'.self::EMAIL)
            ->messages(['string' => 'Enter a valid email address.', 'regex' => 'Enter a valid email address.'])->default('');
    }

    public static function phone(): Field
    {
        return self::required('Phone', 40)->rules('regex:'.self::PHONE)->messages(['regex' => 'Enter a valid phone number.']);
    }

    public static function optionalPhone(): Field
    {
        return (new Field)->pre(self::trim())
            ->rules('nullable', 'string', 'regex:'.self::PHONE)
            ->messages(['string' => 'Enter a valid phone number.', 'regex' => 'Enter a valid phone number.'])->default('');
    }

    /** One of a fixed set, with a single message for missing or unknown values. */
    public static function choice(array $values, string $message, bool $required = true): Field
    {
        return (new Field)->rules($required ? 'required' : 'nullable', Rule::in($values))
            ->messages(['required' => $message, 'in' => $message]);
    }

    /** z.coerce.number(): JavaScript Number() semantics ("" and null → 0, junk → NaN). */
    public static function number(string $message, ?float $min = null, ?float $max = null, ?string $rangeMessage = null): Field
    {
        $finite = function (string $attribute, mixed $value, Closure $fail) use ($message) {
            if (! (is_int($value) || is_float($value)) || ! is_finite((float) $value)) {
                $fail($message);
            }
        };
        $f = (new Field)->pre(fn ($v) => self::coerceNumber($v))
            ->rules('bail', 'required', $finite, 'numeric')->messages(['numeric' => $message, 'required' => $message]);
        if ($min !== null) {
            $f->rules('min:'.$min)->messages(['min' => $rangeMessage ?? $message]);
        }
        if ($max !== null) {
            $f->rules('max:'.$max)->messages(['max' => $rangeMessage ?? $message]);
        }

        return $f;
    }

    public static function coerceNumber(mixed $v): mixed
    {
        return match (true) {
            $v === null => 0,
            is_bool($v) => (int) $v,
            is_int($v), is_float($v) => $v,
            is_string($v) && trim($v) === '' => 0,
            is_string($v) && is_numeric(trim($v)) => trim($v) + 0,
            is_array($v) && $v === [] => 0,
            default => NAN,
        };
    }

    public static function boolean(string $message = 'Expected boolean.', bool $required = false): Field
    {
        return (new Field)->rules($required ? 'required' : 'sometimes', self::strictBool($message))->messages(['required' => $message]);
    }

    private static function strictBool(string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message) {
            if (! is_bool($value)) {
                $fail($message);
            }
        };
    }

    public static function datetime(): Field
    {
        return (new Field)->rules('nullable', 'string', 'regex:'.self::DATETIME)
            ->messages(['string' => 'Use an ISO-8601 date and time.', 'regex' => 'Use an ISO-8601 date and time.']);
    }

    public static function day(): Field
    {
        $m = 'Use a date in YYYY-MM-DD format.';

        // A real calendar day: 1990-13-40 matches the pattern but is not a date.
        return (new Field)->rules('nullable', 'bail', 'string', 'regex:'.self::DAY, fn (string $a, mixed $v, Closure $fail) => Dates::isDay($v) || $fail($m))
            ->messages(['string' => $m, 'regex' => $m]);
    }

    /** The account password policy (PasswordPolicy); optional passwords skip it when absent. */
    public static function password(bool $required = true): Field
    {
        return $required ? (new Field)->rules(new PasswordPolicy) : (new Field)->rules('nullable', new PasswordPolicy(implicit: false));
    }

    /** Nullable object (payment, newPatient). */
    public static function object(array $children): Field
    {
        $f = (new Field)->rules('nullable', 'array')->messages(['array' => 'Expected object.'])->default(null);
        $f->children = $children;

        return $f;
    }

    /** Array of items validated by $each. */
    public static function list(Field $each, ?int $max = null, string $message = 'Expected array.'): Field
    {
        $f = (new Field)->rules('array')->messages(['array' => $message]);
        if ($max !== null) {
            $f->rules("max:{$max}")->messages(['max' => $message]);
        }
        $f->each = $each;

        return $f;
    }
}
