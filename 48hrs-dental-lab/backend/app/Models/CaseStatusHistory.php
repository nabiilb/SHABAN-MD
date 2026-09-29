<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseStatusHistory extends LabModel
{
    protected $table = 'case_status_history';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
