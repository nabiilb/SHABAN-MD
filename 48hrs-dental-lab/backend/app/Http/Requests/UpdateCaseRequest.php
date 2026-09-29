<?php

namespace App\Http\Requests;

use App\Domain\Catalog;

/** Every field optional (partial update). */
class UpdateCaseRequest extends ApiRequest
{
    public static function fields(): array
    {
        $optional = fn (Field $f) => $f->rules('sometimes');

        return [
            'material' => $optional(F::text(120, Field::MISSING)),
            'shade' => $optional(F::required('Shade', 20)),
            'teeth' => $optional(CreateCaseRequest::teeth()),
            'priority' => $optional(F::choice(Catalog::priorities(), 'Select a priority.')),
            'instructions' => $optional(F::text(2000, Field::MISSING)),
            'dentureType' => (new Field)->rules('sometimes', 'nullable', \Illuminate\Validation\Rule::in(Catalog::dentureTypeValues()))->messages(['in' => 'Select the denture type.']),
        ];
    }
}
