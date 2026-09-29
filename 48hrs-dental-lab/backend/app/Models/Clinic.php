<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Clinic extends LabModel
{
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(DentalCase::class, 'clinic_id');
    }
}
