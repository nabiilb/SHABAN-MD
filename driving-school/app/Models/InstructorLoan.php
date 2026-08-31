<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstructorLoan extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['outstanding', 'partially_paid', 'paid', 'cancelled'];

    protected $fillable = [
        'loan_number',
        'instructor_id',
        'amount',
        'remaining_amount',
        'loan_date',
        'due_date',
        'reason',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'loan_date' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InstructorLoanPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isInstructor()) {
            return $query->where('instructor_loans.instructor_id', $user->instructorId() ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }

    public function getPaidAmountAttribute(): float
    {
        return round((float) $this->amount - (float) $this->remaining_amount, 2);
    }

    public function recalculate(): void
    {
        if ($this->status === 'cancelled') {
            return;
        }

        $paid = (float) $this->payments()->sum('amount');
        $remaining = round(max((float) $this->amount - $paid, 0), 2);

        $this->forceFill([
            'remaining_amount' => $remaining,
            'status' => match (true) {
                $remaining <= 0.001 => 'paid',
                $paid > 0 => 'partially_paid',
                default => 'outstanding',
            },
        ])->save();
    }
}
