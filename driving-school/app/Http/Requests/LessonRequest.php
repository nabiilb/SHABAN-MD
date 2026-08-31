<?php

namespace App\Http\Requests;

use App\Models\Lesson;
use App\Models\Student;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        if ($lesson) {
            return $this->user()->can('update', $lesson);
        }

        if (! $this->user()->can('create', Lesson::class)) {
            return false;
        }

        $student = Student::find($this->input('student_id'));

        return $student !== null && $this->user()->can('recordFor', $student);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'lesson_topic_id' => ['required', 'integer', Rule::exists('lesson_topics', 'id')],
            'lesson_date' => ['required', 'date'],
            'topic' => ['nullable', 'string', 'max:180'],
            'performance' => ['nullable', Rule::in(Lesson::PERFORMANCES)],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(Lesson::STATUSES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $vehicleId = $this->input('vehicle_id');

            if (! $vehicleId || $this->user()->isAdmin()) {
                return;
            }

            // An instructor may only book a lesson on a vehicle assigned to him.
            $vehicle = Vehicle::find($vehicleId);

            if (! $vehicle || $vehicle->instructor_id !== $this->user()->instructorId()) {
                $validator->errors()->add('vehicle_id', __('That vehicle is not assigned to you.'));
            }
        });
    }
}
