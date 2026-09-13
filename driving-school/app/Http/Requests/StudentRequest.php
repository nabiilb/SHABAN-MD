<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $student = $this->route('student');

        return $student
            ? $this->user()->can('update', $student)
            : $this->user()->can('create', Student::class);
    }

    public function rules(): array
    {
        $id = $this->route('student')?->id;

        return [
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('students', 'email')->ignore($id)->whereNull('deleted_at')],
            'address' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'license_type' => ['nullable', 'string', 'max:60'],
            'start_date' => ['required', 'date'],
            'required_training_days' => ['required', 'integer', 'min:1', 'max:365'],
            'current_instructor_id' => ['nullable', 'integer', Rule::exists('instructors', 'id')->whereNull('deleted_at')],
            'status' => ['required', Rule::in(Student::STATUSES)],
            'total_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'profile_photo' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function attributes(): array
    {
        return [
            'current_instructor_id' => __('instructor'),
            'required_training_days' => __('required training days'),
        ];
    }
}
