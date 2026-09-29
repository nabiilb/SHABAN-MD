<?php

namespace App\Http\Requests;

use Closure;

/**
 * One request field: how to normalise it (trim, lower-case, coerce like the web
 * app's Zod schemas), the Laravel validation rules and messages, and the default
 * applied after validation. See F for the building blocks.
 */
final class Field
{
    /** @var list<string|object|Closure> */
    public array $rules = [];

    /** @var array<string, string> rule name → message */
    public array $messages = [];

    public ?Closure $pre = null;

    /** Value when the key is absent (or null for nullable fields); MISSING = leave out. */
    public mixed $default = self::MISSING;

    /** @var array<string, Field> for objects (payment, newPatient) */
    public array $children = [];

    public ?Field $each = null;

    public const MISSING = "\0missing";

    public function rules(string|object ...$rules): self
    {
        array_push($this->rules, ...$rules);

        return $this;
    }

    public function messages(array $messages): self
    {
        $this->messages = [...$this->messages, ...$messages];

        return $this;
    }

    public function pre(Closure $fn): self
    {
        $this->pre = $fn;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;

        return $this;
    }
}
