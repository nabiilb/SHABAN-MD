<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Technician extends LabModel
{
    protected function casts(): array
    {
        return ['active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(DentalCase::class, 'technician_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CaseAssignment::class);
    }
}
