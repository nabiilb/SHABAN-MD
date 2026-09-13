<?php

namespace App\Http\Requests;

use App\Models\Setting;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A teacher putting a student in today's waiting line.
 *
 * Deliberately narrower than TrainingQueueEntryRequest: a teacher chooses a
 * student and how long they should train for, and nothing else about the line.
 */
class AddToTrainingQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('addToQueue', TrainingQueueEntry::class);
    }

    public function rules(): array
    {
        return [
            'student_id' => [
                'required', 'integer',
                Rule::exists('students', 'id')->where('status', 'active')->whereNull('deleted_at'),
            ],
            'assigned_duration_minutes' => ['nullable', 'integer', Rule::in(TrainingSession::DURATION_OPTIONS)],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.exists' => __('That student could not be found, or is no longer active.'),
        ];
    }

    /** The duration to carry into the session, falling back to the centre's default. */
    public function queueData(): array
    {
        return [
            'assigned_duration_minutes' => $this->input('assigned_duration_minutes')
                ?: (int) Setting::get('default_training_minutes', 30),
        ];
    }
}
