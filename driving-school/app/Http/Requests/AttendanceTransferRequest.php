<?php

namespace App\Http\Requests;

use App\Models\Instructor;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Transferring a student from the attendance screen. The date is always the
 * current day — it is never taken from the request — so the action can only
 * ever hand over today's attendance and can never rewrite an earlier day.
 */
class AttendanceTransferRequest extends FormRequest
{
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
            'reason' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $student = Student::find($this->input('student_id'));
            $target = Instructor::find($this->input('to_instructor_id'));

            if (! $student || ! $target) {
                return;
            }

            if ($student->current_instructor_id === $target->id) {
                $validator->errors()->add('to_instructor_id', __('The student is already assigned to this instructor.'));
            }

            if ($target->status !== 'active') {
                $validator->errors()->add('to_instructor_id', __('The receiving instructor is not active.'));
            }

            // An instructor can never hand a student to himself.
            if ($this->user()->isInstructor() && $target->id === $this->user()->instructorId()) {
                $validator->errors()->add('to_instructor_id', __('Select a different instructor to transfer to.'));
            }
        });
    }

    public function transferReason(): string
    {
        return $this->filled('reason')
            ? $this->string('reason')->toString()
            : __('Transferred during attendance');
    }
}
