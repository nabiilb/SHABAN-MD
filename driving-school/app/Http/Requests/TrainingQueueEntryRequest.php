<?php

namespace App\Http\Requests;

use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrainingQueueEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TrainingQueueEntry::class);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'preferred_instructor_id' => [
                'nullable', 'integer',
                Rule::exists('instructors', 'id')->where('status', 'active')->whereNull('deleted_at'),
            ],
            'assigned_duration_minutes' => ['nullable', 'integer', Rule::in(TrainingSession::DURATION_OPTIONS)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
