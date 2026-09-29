<?php

namespace App\Http\Requests;

class ForgotPasswordRequest extends ApiRequest
{
    public static function fields(): array
    {
        return ['email' => F::email()];
    }
}
