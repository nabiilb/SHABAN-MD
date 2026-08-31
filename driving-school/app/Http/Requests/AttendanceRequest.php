<?php

namespace App\Http\Requests;

use App\Models\Attendance;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attendance = $this->route('attendance');

        if ($attendance) {
            return $this->user()->can('update', $attendance);
        }

        if (! $this->user()->can('create', Attendance::class)) {
            return false;
        }

        // The student must be one the actor is allowed to record for. This is
        // the backend check — the form's student list is only a convenience.
        $student = Student::find($this->input('student_id'));

        return $student !== null && $this->user()->can('recordFor', $student);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'status' => ['required', Rule::in(Attendance::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // One check-in per student per day, unless an admin has switched
            // duplicates on in Settings.
            if (Setting::flag('allow_duplicate_attendance')) {
                return;
            }

            $exists = Attendance::query()
                ->where('student_id', $this->input('student_id'))
                ->whereDate('attendance_date', $this->date('attendance_date'))
                ->when($this->route('attendance'), fn ($q, $a) => $q->whereKeyNot($a->id))
                ->exists();

            if ($exists) {
                $validator->errors()->add(
                    'attendance_date',
                    __('This student already has attendance recorded for that date.')
                );
            }
        });
    }
}
