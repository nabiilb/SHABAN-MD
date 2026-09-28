<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FuelRecord extends Model
{
    use HasFactory, SoftDeletes;

    /** Submitted by an instructor; no ledger entry exists yet. */
    public const PENDING = 'pending';

    /** Posted to the ledger — an expense for cash, a debt for credit. */
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED];

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
        'submission_token',
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
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Fuel an instructor may read: the fill-ups recorded against them, and
     * everything bought for a vehicle assigned to them. Both relationships are
     * already on the table — `instructor_id` names who took the fuel, and
     * `vehicle_id` reaches the instructor through the vehicle's own assignment
     * — so a fill-up on somebody else's car stays out of reach.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isInstructor()) {
            $instructorId = $user->instructorId() ?? 0;

            return $query->where(fn (Builder $q) => $q
                ->where('fuel_records.instructor_id', $instructorId)
                ->orWhereHas('vehicle', fn (Builder $v) => $v->where('vehicles.instructor_id', $instructorId)));
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Whether this record has reached the company ledger. */
    public function isPosted(): bool
    {
        return $this->company_expense_id !== null || $this->company_debt_id !== null;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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
