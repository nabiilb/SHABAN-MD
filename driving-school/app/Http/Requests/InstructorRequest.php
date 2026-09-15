<?php

namespace App\Http\Requests;

use App\Models\Instructor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class InstructorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $instructor = $this->route('instructor');

        return $instructor
            ? $this->user()->can('update', $instructor)
            : $this->user()->can('create', Instructor::class);
    }

    public function rules(): array
    {
        $instructor = $this->route('instructor');

        return [
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('instructors', 'email')->ignore($instructor?->id)->whereNull('deleted_at')],
            'address' => ['nullable', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:150'],
            'joining_date' => ['required', 'date'],
            'status' => ['required', Rule::in(Instructor::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Optional linked login account.
            'create_account' => ['nullable', 'boolean'],
            'account_email' => [
                'nullable',
                'required_if:create_account,1',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($instructor?->user_id)->whereNull('deleted_at'),
            ],
            'account_password' => [
                'nullable',
                $instructor?->user_id ? 'sometimes' : 'required_if:create_account,1',
                Password::min(8),
            ],
        ];
    }
}
