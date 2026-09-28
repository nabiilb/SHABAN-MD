<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPES = ['garage', 'petrol_station', 'spare_parts', 'office_supplier', 'other'];

    protected $fillable = [
        'supplier_number',
        'name',
        'phone',
        'email',
        'address',
        'supplier_type',
        'notes',
        'status',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(CompanyExpense::class);
    }

    public function companyDebts(): HasMany
    {
        return $this->hasMany(CompanyDebt::class);
    }

    public function debtPayments(): HasManyThrough
    {
        return $this->hasManyThrough(DebtPayment::class, CompanyDebt::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Sum of everything still owed to this supplier. */
    public function getOutstandingBalanceAttribute(): float
    {
        return (float) $this->companyDebts()
            ->whereIn('status', ['outstanding', 'partially_paid', 'overdue'])
            ->sum('remaining_amount');
    }
}
