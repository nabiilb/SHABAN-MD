<?php

namespace App\Http\Requests;

use App\Models\Attendance;
use App\Models\Lesson;
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

        // The student must be one the actor owns ON THE DATE BEING RECORDED.
        // This is the backend check — the form's student list is only a
        // convenience, and the date decides who may record it.
        $student = Student::find($this->input('student_id'));

        return $student !== null
            && $this->user()->can('recordFor', [$student, $this->input('attendance_date') ?: today()]);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'status' => ['required', Rule::in(Attendance::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],

            // The lesson worked on that day is part of the day's record, so a
            // student marked present must have one.
            'lesson_topic_id' => [
                Rule::requiredIf(fn () => $this->input('status') === 'present'),
                'nullable',
                'integer',
                Rule::exists('lesson_topics', 'id')->where('is_active', true),
            ],
            'performance' => ['nullable', Rule::in(Lesson::PERFORMANCES)],
            'topic' => ['nullable', 'string', 'max:180'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
        ];
    }

    /** The day's lesson, as posted alongside the attendance. */
    public function lessonData(): array
    {
        return [
            'lesson_topic_id' => $this->input('lesson_topic_id'),
            'performance' => $this->input('performance'),
            'topic' => $this->input('topic'),
            'vehicle_id' => $this->input('vehicle_id'),
            'duration_minutes' => $this->input('duration_minutes') ?: 60,
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
