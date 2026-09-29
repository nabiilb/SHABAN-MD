<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityIssue extends LabModel
{
    public $timestamps = false;

    public function qualityCheck(): BelongsTo
    {
        return $this->belongsTo(QualityCheck::class);
    }
}
