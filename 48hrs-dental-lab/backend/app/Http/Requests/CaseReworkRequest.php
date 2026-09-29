<?php

namespace App\Http\Requests;

class CaseReworkRequest extends ApiRequest
{
    public static function fields(): array
    {
        return ['note' => F::text(1000, Field::MISSING)];
    }
}
