<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends LabModel
{
    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(DentalCase::class, 'patient_id');
    }
}
