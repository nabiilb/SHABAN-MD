<?php

namespace App\Http\Requests;

use App\Domain\Catalog;
use Illuminate\Validation\Rule;

class CaseQcRequest extends ApiRequest
{
    public static function fields(): array
    {
        $issue = (new Field)->rules(Rule::in(Catalog::qcIssues()))->messages(['in' => 'Unknown QC issue.']);

        return [
            'result' => F::choice(['pass', 'fail'], 'Result must be pass or fail.'),
            'issues' => F::list($issue, count(Catalog::qcIssues()))->default([]),
            'notes' => F::text(1000),
        ];
    }
}
