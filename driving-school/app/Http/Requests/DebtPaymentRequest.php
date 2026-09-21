<?php

namespace App\Http\Requests;

use App\Models\DebtPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DebtPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', DebtPayment::class);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(DebtPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $debt = $this->route('debt');

            if (! $debt) {
                return;
            }

            if ($debt->status === 'cancelled') {
                $validator->errors()->add('amount', __('A cancelled debt cannot be paid.'));

                return;
            }

            if ((float) $this->input('amount') > round((float) $debt->remaining_amount, 2) + 0.001) {
                $validator->errors()->add('amount', __('Payment cannot exceed the remaining debt of :amount.', [
                    'amount' => number_format((float) $debt->remaining_amount, 2),
                ]));
            }

            if ($this->date('payment_date')?->lt($debt->debt_date)) {
                $validator->errors()->add('payment_date', __('The payment date cannot be before the debt date.'));
            }
        });
    }
}
