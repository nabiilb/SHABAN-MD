<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseAttachment extends LabModel
{
    public const UPDATED_AT = null;

    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'datetime'];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function dentalCase(): BelongsTo
    {
        return $this->belongsTo(DentalCase::class, 'case_id');
    }
}
