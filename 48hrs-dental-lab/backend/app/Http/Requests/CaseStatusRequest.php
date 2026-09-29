<?php

namespace App\Http\Requests;

use App\Domain\Catalog;
use App\Domain\Workflow;

class CaseStatusRequest extends ApiRequest
{
    public static function fields(): array
    {
        return [
            'status' => F::choice(Workflow::statuses(), 'Choose a valid status.'),
            'note' => F::text(1000, Field::MISSING),
            'payment' => F::object([
                'amount' => F::number('Enter an amount greater than zero.')->rules('required'),
                'method' => F::choice(Catalog::paymentMethods(), 'Choose a payment method.'),
                'reference' => F::text(120, Field::MISSING),
            ]),
        ];
    }
}
