<?php

namespace App\Http\Requests;

use App\Domain\Catalog;

class CaseDeliveryRequest extends ApiRequest
{
    public static function fields(): array
    {
        return [
            'status' => F::choice(['out_for_delivery', 'delivered'], 'Status must be out_for_delivery or delivered.'),
            'method' => F::choice(Catalog::deliveryMethods(), 'Choose a delivery method.'),
            'courierName' => F::text(120, Field::MISSING),
            'deliveredTo' => F::text(160, Field::MISSING),
            'receivedBy' => F::text(120, Field::MISSING),
            'notes' => F::text(1000, Field::MISSING),
        ];
    }
}
