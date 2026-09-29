<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualityCheck extends LabModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['rework_required' => 'boolean', 'checked_at' => 'datetime'];
    }

    public function issues(): HasMany
    {
        return $this->hasMany(QualityIssue::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_id');
    }

    public function dentalCase(): BelongsTo
    {
        return $this->belongsTo(DentalCase::class, 'case_id');
    }
}
