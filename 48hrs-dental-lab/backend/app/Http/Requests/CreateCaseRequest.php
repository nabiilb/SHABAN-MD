<?php

namespace App\Http\Requests;

use App\Domain\Catalog;

class CreateCaseRequest extends ApiRequest
{
    public static function teeth(): Field
    {
        $tooth = (new Field)->rules('integer', 'min:1', 'max:32')->messages(array_fill_keys(['integer', 'min', 'max'], 'Teeth must use universal numbering 1–32.'));

        return F::list($tooth, 32, 'Teeth must use universal numbering 1–32.');
    }

    public static function fields(): array
    {
        return [
            'patientId' => F::optionalId(),
            'newPatient' => F::object([
                'name' => F::required('Patient name'),
                'code' => F::text(40, Field::MISSING),
                'phone' => F::optionalPhone(),
            ]),
            'doctorId' => F::id('Doctor'),
            // Ignored for clinic-portal users (their own clinic is used).
            'clinicId' => F::text(64),
            'serviceId' => F::id('Service'),
            'material' => F::text(120),
            'shade' => F::required('Shade', 20),
            'teeth' => self::teeth()->default([]),
            'dentureType' => F::choice(Catalog::dentureTypeValues(), 'Select the denture type.', required: false)->default(null),
            'priority' => F::choice(Catalog::priorities(), 'Select a priority.'),
            'instructions' => F::text(2000),
            'receiveNow' => F::boolean()->default(true),
            'dueAt' => F::datetime()->default(null),
        ];
    }
}
