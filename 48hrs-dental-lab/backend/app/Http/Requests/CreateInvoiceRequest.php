<?php

namespace App\Http\Requests;

class CreateInvoiceRequest extends ApiRequest
{
    public static function fields(): array
    {
        return ['caseId' => F::id('Case')];
    }
}
