<?php

namespace App\Http\Requests;

use App\Domain\Catalog;

class ServiceRequest extends ApiRequest
{
    public static function fields(): array
    {
        return [
            'name' => F::required('Service name'),
            'caseType' => F::choice(Catalog::caseTypes(), 'Choose a case type.'),
            'unitMode' => F::choice(Catalog::SERVICE_UNIT_MODES, 'Choose a billing unit.'),
            'unitPrice' => F::number('Enter a price of 0 or more.', 0)->rules('max:100000')->messages(['max' => 'Enter a realistic price.']),
            'defaultMaterial' => F::required('Default material'),
            'active' => F::boolean()->default(true),
        ];
    }
}
