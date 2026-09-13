<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FuelRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'fuel_number',
        'vehicle_id',
        'instructor_id',
        'supplier_id',
        'company_expense_id',
        'company_debt_id',
        'liters',
        'price_per_liter',
        'amount',
        'odometer',
        'fuel_date',
        'is_credit',
        'payment_method',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fuel_date' => 'date',
            'liters' => 'decimal:2',
            'price_per_liter' => 'decimal:2',
            'amount' => 'decimal:2',
            'is_credit' => 'boolean',
            'odometer' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(CompanyExpense::class, 'company_expense_id');
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(CompanyDebt::class, 'company_debt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
