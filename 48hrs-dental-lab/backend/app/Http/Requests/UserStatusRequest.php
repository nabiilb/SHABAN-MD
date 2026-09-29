<?php

namespace App\Http\Requests;

class UserStatusRequest extends ApiRequest
{
    public static function fields(): array
    {
        return ['active' => F::boolean('Choose enabled or disabled.', required: true)];
    }
}
