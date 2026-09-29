<?php

namespace App\Http\Requests;

use App\Domain\Catalog;

class ClinicRequest extends ApiRequest
{
    public static function fields(): array
    {
        return [
            'name' => F::required('Clinic name'),
            'contactPerson' => F::text(120),
            'phone' => F::phone(),
            'email' => F::optionalEmail(),
            'address' => F::text(300),
            'status' => F::choice(Catalog::RECORD_STATUSES, 'Choose a status.', required: false)->default('active'),
            'notes' => F::text(1000),
        ];
    }
}
