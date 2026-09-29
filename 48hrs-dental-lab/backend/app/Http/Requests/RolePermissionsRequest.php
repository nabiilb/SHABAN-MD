<?php

namespace App\Http\Requests;

class RolePermissionsRequest extends ApiRequest
{
    public static function fields(): array
    {
        $key = (new Field)->rules('string', 'max:64')->messages(['string' => 'Expected string.', 'max' => 'Expected string.']);

        return ['permissions' => F::list($key, 200, 'Expected array, received string')->rules('required')->messages(['required' => 'Required'])];
    }
}
