<?php

namespace App\Http\Requests;

use App\Domain\Catalog;

class PatientRequest extends ApiRequest
{
    public static function fields(): array
    {
        return [
            'name' => F::required('Patient name'),
            'code' => F::text(40, Field::MISSING),
            'phone' => F::optionalPhone(),
            'email' => F::optionalEmail(),
            'gender' => F::choice(Catalog::GENDERS, 'Choose male or female.', required: false)->default(null),
            'dateOfBirth' => F::day()->messages(['string' => 'Invalid input'])->pre(fn ($v) => $v === '' ? null : $v)->default(null),
            'clinicId' => F::optionalId(),
            'notes' => F::text(1000),
        ];
    }
}
