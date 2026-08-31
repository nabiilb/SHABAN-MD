<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class DebtPayment extends Model
{
    use HasFactory, SoftDeletes;

    public const METHODS = ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'other'];

    protected $fillable = [
        'company_debt_id',
        'amount',
        'payment_date',
        'payment_method',
        'reference',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function companyDebt(): BelongsTo
    {
        return $this->belongsTo(CompanyDebt::class);
    }

    /** Every debt payment produces exactly one company expense. */
    public function expense(): HasOne
    {
        return $this->hasOne(CompanyExpense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
