<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class SettingsRequest extends ApiRequest
{
    private static function bounded(string $label, int $min, int $max): Field
    {
        $m = "{$label} must be between {$min} and {$max}.";

        return F::number($m, $min, $max, $m);
    }

    public static function fields(): array
    {
        return [
            'labName' => F::required('Lab name'),
            'phone' => F::text(40),
            'email' => F::optionalEmail(),
            'address' => F::text(300),
            'currency' => (new Field)->pre(fn ($v) => is_string($v) ? trim($v) : $v)->rules('required', 'string', 'regex:/^[A-Z]{3}$/')->messages(['required' => 'Required', ...array_fill_keys(['string', 'regex'], 'Use a 3-letter ISO currency code, e.g. USD.')]),
            'slaHours' => self::bounded('Turnaround', 4, 240),
            'atRiskHours' => self::bounded('At-risk threshold', 1, 72),
            'criticalHours' => self::bounded('Critical threshold', 1, 48),
            'emergencyFeePerUnit' => self::bounded('Emergency fee', 0, 1000),
            'invoiceDueDays' => self::bounded('Invoice terms', 0, 120),
        ];
    }

    protected static function refine(array $data, Validator $v): void
    {
        $critical = $data['criticalHours'] ?? null;
        $risk = $data['atRiskHours'] ?? null;
        if (is_numeric($critical) && is_numeric($risk) && ! ($critical < $risk)) {
            $v->errors()->add('criticalHours', 'Critical must be lower than the at-risk threshold.');
        }
    }
}
