<?php

namespace App\Http\Requests;

use App\Models\InstructorLoan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InstructorLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $loan
            ? $this->user()->can('update', $loan)
            : $this->user()->can('create', InstructorLoan::class);
    }

    public function rules(): array
    {
        return [
            'instructor_id' => ['required', 'integer', Rule::exists('instructors', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'loan_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:loan_date'],
            'reason' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
