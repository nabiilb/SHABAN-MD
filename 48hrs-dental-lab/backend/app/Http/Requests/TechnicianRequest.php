<?php

namespace App\Http\Requests;

class TechnicianRequest extends ApiRequest
{
    public static function fields(): array
    {
        return [
            'name' => F::required('Name'),
            'email' => F::email(),
            'phone' => F::phone(),
            'specialty' => F::required('Specialty'),
            'active' => F::boolean()->default(true),
        ];
    }
}
