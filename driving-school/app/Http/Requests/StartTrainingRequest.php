<?php

namespace App\Http\Requests;

use App\Models\TrainingSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TrainingSession::class);
    }

    public function rules(): array
    {
        return [
            'training_queue_entry_id' => [
                'required', 'integer',
                Rule::exists('training_queue_entries', 'id'),
            ],
            'assigned_duration_minutes' => [
                'required', 'integer',
                Rule::in(TrainingSession::DURATION_OPTIONS),
            ],
            'lesson_topic_id' => ['nullable', 'integer', Rule::exists('lesson_topics', 'id')->where('is_active', true)],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
        ];
    }

    public function options(): array
    {
        return [
            'assigned_duration_minutes' => $this->integer('assigned_duration_minutes'),
            'lesson_topic_id' => $this->input('lesson_topic_id') ?: null,
            'vehicle_id' => $this->input('vehicle_id') ?: null,
        ];
    }
}
