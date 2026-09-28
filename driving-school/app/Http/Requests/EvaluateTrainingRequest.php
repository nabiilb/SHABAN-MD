<?php

namespace App\Http\Requests;

use App\Models\Attendance;
use App\Models\TrainingEvaluation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluateTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');

        return $session !== null && $this->user()->can('evaluate', $session);
    }

    public function rules(): array
    {
        return [
            'attendance_status' => ['required', Rule::in(Attendance::STATUSES)],
            // A rating is expected whenever the student actually attended.
            'evaluation' => [
                Rule::requiredIf(fn () => $this->input('attendance_status') === 'present'),
                'nullable',
                Rule::in(TrainingEvaluation::RATINGS),
            ],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['evaluation' => __('evaluation')];
    }
}
