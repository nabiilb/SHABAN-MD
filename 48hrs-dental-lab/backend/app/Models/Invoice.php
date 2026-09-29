<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends LabModel
{
    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'emergency_fee' => 'float',
            'discount' => 'float',
            'total' => 'float',
            'amount_paid' => 'float',
            'issued_at' => 'datetime',
            'due_date' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function dentalCase(): BelongsTo
    {
        return $this->belongsTo(DentalCase::class, 'case_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
