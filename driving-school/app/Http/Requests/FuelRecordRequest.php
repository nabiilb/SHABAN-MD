<?php

namespace App\Http\Requests;

use App\Models\FuelRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FuelRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $fuel = $this->route('fuel');

        return $fuel
            ? $this->user()->can('update', $fuel)
            : $this->user()->can('create', FuelRecord::class);
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'instructor_id' => ['nullable', 'integer', Rule::exists('instructors', 'id')->whereNull('deleted_at')],
            'supplier_id' => ['nullable', 'integer', 'required_if:is_credit,1', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'liters' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'price_per_liter' => ['required', 'numeric', 'min:0.01', 'max:9999.99'],
            'odometer' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'fuel_date' => ['required', 'date', 'before_or_equal:today'],
            'is_credit' => ['nullable', 'boolean'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'mobile_money', 'cheque', 'other'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
