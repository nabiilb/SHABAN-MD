<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;

/**
 * Base for every JSON body: fields() declares normalisation, Laravel rules and
 * messages (F), refine() adds cross-field rules, payload() returns the
 * normalised input with defaults. A failure is the API's 422 { message, errors }.
 * Authorisation is done by route middleware and policies, not here.
 */
abstract class ApiRequest extends FormRequest
{
    /** @return array<string, Field> */
    abstract public static function fields(): array;

    /** Cross-field rules (Zod refine), run after the field rules. */
    protected static function refine(array $data, LaravelValidator $v): void {}

    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return static::normalize(static::fields(), $this->json()->all() ?: $this->all());
    }

    public function rules(): array
    {
        [$rules] = static::compile(static::fields());

        return $rules;
    }

    public function messages(): array
    {
        [, $messages] = static::compile(static::fields());

        return $messages;
    }

    public function withValidator(LaravelValidator $v): void
    {
        $v->after(fn (LaravelValidator $v) => static::refine($v->getData(), $v));
    }

    /** The normalised body with defaults (only valid after validation). */
    public function payload(): array
    {
        return static::finalize(static::fields(), $this->validationData());
    }

    /**
     * Validation without HTTP (parity tests).
     *
     * @return array{ok: bool, errors?: array<string, list<string>>, data?: array}
     */
    public static function check(array $input): array
    {
        $fields = static::fields();
        $data = static::normalize($fields, $input);
        [$rules, $messages] = static::compile($fields);
        $v = Validator::make($data, $rules, $messages);
        $v->after(fn (LaravelValidator $v) => static::refine($v->getData(), $v));
        if ($v->fails()) {
            return ['ok' => false, 'errors' => $v->errors()->toArray()];
        }

        return ['ok' => true, 'data' => static::finalize($fields, $data)];
    }

    /** @param array<string, Field> $fields */
    protected static function normalize(array $fields, array $input): array
    {
        foreach ($fields as $key => $f) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if ($f->pre) {
                $value = ($f->pre)($value);
            }
            if ($f->children && $value === null) {
                // An absent object: its fields are not validated (exclude_without).
                unset($input[$key]);

                continue;
            }
            if ($f->children && is_array($value)) {
                $value = static::normalize($f->children, $value);
            }
            if ($f->each && is_array($value) && array_is_list($value) && $f->each->pre) {
                $value = array_map($f->each->pre, $value);
            }
            $input[$key] = $value;
        }

        return $input;
    }

    /** @param array<string, Field> $fields  @return array{0: array, 1: array} */
    protected static function compile(array $fields, string $prefix = ''): array
    {
        $rules = [];
        $messages = [];
        foreach ($fields as $key => $f) {
            $path = $prefix.$key;
            $rules[$path] = $f->rules;
            foreach ($f->messages as $rule => $message) {
                $messages["{$path}.{$rule}"] = $message;
            }
            if ($f->children) {
                [$r, $m] = static::compile($f->children, "{$path}.");
                $r = array_map(fn ($childRules) => ["exclude_without:{$path}", ...$childRules], $r);
                $rules = [...$rules, ...$r];
                $messages = [...$messages, ...$m];
            }
            if ($f->each) {
                $rules["{$path}.*"] = $f->each->rules;
                foreach ($f->each->messages as $rule => $message) {
                    $messages["{$path}.*.{$rule}"] = $message;
                }
            }
        }

        return [$rules, $messages];
    }

    /** Keeps declared keys only and applies defaults. @param array<string, Field> $fields */
    protected static function finalize(array $fields, array $data): array
    {
        $out = [];
        foreach ($fields as $key => $f) {
            $present = array_key_exists($key, $data) && $data[$key] !== null;
            if (! $present) {
                if ($f->default !== Field::MISSING) {
                    $out[$key] = $f->default;
                } elseif (array_key_exists($key, $data)) {
                    $out[$key] = null;
                }

                continue;
            }
            $value = $data[$key];
            if ($f->children && is_array($value)) {
                $value = static::finalize($f->children, $value);
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
