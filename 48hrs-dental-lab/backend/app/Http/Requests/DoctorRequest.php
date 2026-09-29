<?php

namespace App\Http\Requests;

use App\Domain\Catalog;

class DoctorRequest extends ApiRequest
{
    public static function fields(): array
    {
        return [
            'name' => F::required('Name'),
            'clinicId' => F::id('Clinic'),
            'phone' => F::phone(),
            'email' => F::optionalEmail(),
            'specialty' => F::text(120),
            'status' => F::choice(Catalog::RECORD_STATUSES, 'Choose a status.', required: false)->default('active'),
        ];
    }
}
