<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['available', 'in_training', 'maintenance', 'inactive'];

    protected $fillable = [
        'vehicle_number',
        'plate_number',
        'make',
        'model',
        'year',
        'color',
        'mileage',
        'status',
        'instructor_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'mileage' => 'integer',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(CompanyExpense::class);
    }

    public function fuelRecords(): HasMany
    {
        return $this->hasMany(FuelRecord::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isInstructor()) {
            return $query->where('vehicles.instructor_id', $user->instructorId() ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }

    public function getLabelAttribute(): string
    {
        return trim("{$this->make} {$this->model}");
    }
}
