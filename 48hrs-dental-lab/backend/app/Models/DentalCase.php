<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DentalCase extends LabModel
{
    protected $table = 'cases';

    public const TIMESTAMP_FIELDS = ['submitted_at', 'received_at', 'due_at', 'assigned_at', 'production_started_at', 'production_completed_at', 'qc_completed_at', 'ready_at', 'delivered_at', 'completed_at', 'cancelled_at', 'at_risk_notified_at', 'overdue_notified_at', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'teeth' => 'array',
            'units' => 'integer',
            'unit_price' => 'float',
            'emergency_fee' => 'float',
            'total' => 'float',
            'rework_count' => 'integer',
            ...array_fill_keys(self::TIMESTAMP_FIELDS, 'datetime'),
        ];
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

    public function service(): BelongsTo
    {
        return $this->belongsTo(LabService::class, 'service_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CaseNote::class, 'case_id')->orderBy('created_at')->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(CaseStatusHistory::class, 'case_id')->orderBy('created_at')->orderBy('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CaseAssignment::class, 'case_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CaseAttachment::class, 'case_id')->orderBy('created_at')->orderBy('id');
    }

    public function qualityChecks(): HasMany
    {
        return $this->hasMany(QualityCheck::class, 'case_id')->orderBy('checked_at')->orderBy('id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'case_id')->orderBy('created_at')->orderBy('id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'case_id');
    }
}
