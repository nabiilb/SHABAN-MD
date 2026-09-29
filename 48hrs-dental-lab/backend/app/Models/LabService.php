<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class LabService extends LabModel
{
    protected function casts(): array
    {
        return ['unit_price' => 'float', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function cases(): HasMany
    {
        return $this->hasMany(DentalCase::class, 'service_id');
    }
}
