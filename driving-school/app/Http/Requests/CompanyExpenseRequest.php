<?php

namespace App\Http\Requests;

use App\Models\CompanyExpense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense
            ? $this->user()->can('update', $expense)
            : $this->user()->can('create', CompanyExpense::class);
    }

    public function rules(): array
    {
        return [
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', Rule::in(CompanyExpense::METHODS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
