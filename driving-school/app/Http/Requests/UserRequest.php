<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user
            ? $this->user()->can('update', $user)
            : $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        $id = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($id)->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:30'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'locale' => ['required', Rule::in(array_keys(config('app.supported_locales')))],
            'is_active' => ['nullable', 'boolean'],
            'password' => [$id ? 'nullable' : 'required', 'confirmed', Password::min(8)],

            // Only read when the role is instructor; the profile is created
            // from the account either way, so none of these is required.
            'joining_date' => ['nullable', 'date'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** The extra fields an instructor profile takes, if the form offered them. */
    public function instructorProfileData(): array
    {
        return array_filter([
            'joining_date' => $this->input('joining_date'),
            'qualification' => $this->input('qualification'),
            'address' => $this->input('address'),
            'notes' => $this->input('notes'),
        ], fn ($value) => filled($value));
    }
}
