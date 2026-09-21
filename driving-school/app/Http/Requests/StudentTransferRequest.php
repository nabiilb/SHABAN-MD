<?php

namespace App\Http\Requests;

use App\Models\Instructor;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StudentTransferRequest extends FormRequest
{
    /**
     * Only the instructor a student is CURRENTLY assigned to (or an admin) may
     * move him. Posting another instructor's student id returns 403.
     */
    public function authorize(): bool
    {
        $student = Student::find($this->input('student_id'));

        return $student !== null && $this->user()->can('transfer', $student);
    }

    protected function failedAuthorization(): void
    {
        abort(403, __('You are not authorized to transfer this student.'));
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'to_instructor_id' => ['required', 'integer', Rule::exists('instructors', 'id')->whereNull('deleted_at')],
            'transfer_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $student = Student::find($this->input('student_id'));
            $target = Instructor::find($this->input('to_instructor_id'));

            if ($student && $target && $student->current_instructor_id === $target->id) {
                $validator->errors()->add('to_instructor_id', __('The student is already assigned to this instructor.'));
            }

            if ($target && $target->status !== 'active') {
                $validator->errors()->add('to_instructor_id', __('The receiving instructor is not active.'));
            }
        });
    }
}
