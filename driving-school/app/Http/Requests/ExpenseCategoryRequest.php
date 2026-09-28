<?php

namespace App\Http\Requests;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category
            ? $this->user()->can('update', $category)
            : $this->user()->can('create', ExpenseCategory::class);
    }

    public function rules(): array
    {
        $id = $this->route('category')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'name_so' => ['nullable', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('expense_categories', 'code')->ignore($id)],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }
}
