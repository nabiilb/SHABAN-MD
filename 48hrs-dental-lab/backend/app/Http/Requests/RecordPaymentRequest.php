<?php

namespace App\Http\Requests;

use App\Domain\Catalog;

class RecordPaymentRequest extends ApiRequest
{
    public static function fields(): array
    {
        return [
            'invoiceId' => F::id('Invoice'),
            'amount' => F::number('Enter an amount greater than zero.')->rules('required'),
            'method' => F::choice(Catalog::paymentMethods(), 'Choose a payment method.'),
            'reference' => F::text(120),
            'notes' => F::text(500),
            'paidAt' => F::datetime(),
        ];
    }
}
