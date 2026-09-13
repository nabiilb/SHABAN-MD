<?php

namespace App\Http\Requests;

use App\Models\CompanyDebt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyDebtRequest extends FormRequest
{
    public function authorize(): bool
    {
        $debt = $this->route('debt');

        return $debt
            ? $this->user()->can('update', $debt)
            : $this->user()->can('create', CompanyDebt::class);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'expense_category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:200'],
            'original_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'debt_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:debt_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(CompanyDebt::STATUSES)],
        ];
    }
}
