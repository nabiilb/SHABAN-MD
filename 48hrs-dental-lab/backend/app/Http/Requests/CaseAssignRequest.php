<?php

namespace App\Http\Requests;

/** A missing technician is reported by the workflow input rules ("Choose a technician."), like every other step rule. */
class CaseAssignRequest extends ApiRequest
{
    public static function fields(): array
    {
        return ['technicianId' => F::text(64), 'note' => F::text(1000, Field::MISSING)];
    }
}
