<?php

namespace App\Http\Requests;

use App\Models\Student;
use App\Models\TrainingQueueEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An instructor putting right the days a student has left.
 *
 * Whoever may put a student in the queue may correct the figure the queue
 * shows them: it is the same screen, the same students and the same judgement
 * about who is fit to be trained today.
 */
class CorrectRemainingDaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('addToQueue', TrainingQueueEntry::class);
    }

    public function rules(): array
    {
        /** @var Student $student */
        $student = $this->route('student');

        return [
            'remaining_days' => [
                'required', 'integer', 'min:0',
                // A course cannot have more days left than it has days.
                'max:'.max((int) $student->required_training_days, 0),
            ],
        ];
    }

    public function messages(): array
    {
        /** @var Student $student */
        $student = $this->route('student');

        return [
            'remaining_days.max' => __('Remaining days cannot be more than the :count day course.', [
                'count' => (int) $student->required_training_days,
            ]),
            'remaining_days.min' => __('Remaining days cannot be negative.'),
        ];
    }
}
