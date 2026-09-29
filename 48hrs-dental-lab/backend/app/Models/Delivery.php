<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends LabModel
{
    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime', 'delivered_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function dentalCase(): BelongsTo
    {
        return $this->belongsTo(DentalCase::class, 'case_id');
    }
}
