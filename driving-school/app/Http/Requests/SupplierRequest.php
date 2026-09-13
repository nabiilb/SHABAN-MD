<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $supplier = $this->route('supplier');

        return $supplier
            ? $this->user()->can('update', $supplier)
            : $this->user()->can('create', Supplier::class);
    }

    public function rules(): array
    {
        $id = $this->route('supplier')?->id;

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('suppliers', 'name')->ignore($id)->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'supplier_type' => ['required', Rule::in(Supplier::TYPES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
