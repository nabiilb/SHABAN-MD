<?php

namespace App\Http\Requests;

use App\Domain\Permissions;

class UserRequest extends ApiRequest
{
    public static function fields(): array
    {
        return [
            'name' => F::required('Full name'),
            'email' => F::email(),
            'phone' => F::optionalPhone(),
            'role' => F::choice(Permissions::ROLE_ORDER, 'Choose a role.'),
            'active' => F::boolean()->default(true),
            'clinicId' => F::optionalId(),
            'technicianId' => F::optionalId(),
            // Only on create, or when an admin resets it. Blank = unchanged.
            'password' => F::password(required: false)->pre(fn ($v) => $v === '' ? null : $v),
        ];
    }
}
