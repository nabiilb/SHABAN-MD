<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class ResetPasswordRequest extends ApiRequest
{
    public static function fields(): array
    {
        return [
            'token' => F::required('Reset token', 200),
            'email' => F::email(),
            'password' => F::password(),
            'passwordConfirmation' => (new Field)->rules('required', 'string')->messages(['required' => 'Confirm the password.', 'string' => 'Confirm the password.']),
        ];
    }

    protected static function refine(array $data, Validator $v): void
    {
        if (($data['password'] ?? null) !== ($data['passwordConfirmation'] ?? null)) {
            $v->errors()->add('passwordConfirmation', 'Passwords do not match.');
        }
    }
}
