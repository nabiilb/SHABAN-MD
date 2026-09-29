<?php

namespace App\Http\Requests;

class LoginRequest extends ApiRequest
{
    public static function fields(): array
    {
        return [
            'email' => F::email(),
            'password' => (new Field)->rules('required', 'string', 'max:200')->messages(['required' => 'Password is required.', 'string' => 'Password is required.', 'max' => 'Password is required.']),
        ];
    }
}
