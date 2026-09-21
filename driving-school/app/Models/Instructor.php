<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Instructor extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['active', 'inactive', 'suspended'];

    protected $fillable = [
        'instructor_number',
        'user_id',
        'full_name',
        'phone',
        'email',
        'address',
        'qualification',
        'joining_date',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return ['joining_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Students CURRENTLY assigned to this instructor. */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'current_instructor_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(StudentInstructorAssignment::class);
    }

    public function transfersOut(): HasMany
    {
        return $this->hasMany(StudentTransfer::class, 'from_instructor_id');
    }

    public function transfersIn(): HasMany
    {
        return $this->hasMany(StudentTransfer::class, 'to_instructor_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(InstructorLoan::class);
    }

    public function fuelRecords(): HasMany
    {
        return $this->hasMany(FuelRecord::class);
    }

    /* ----------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Backend enforced visibility. An instructor may only ever resolve
     * his own instructor row; a student may resolve his current instructor.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isInstructor()) {
            return $query->where('instructors.id', $user->instructorId() ?? 0);
        }

        if ($user->isStudent()) {
            return $query->where('instructors.id', $user->student?->current_instructor_id ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }

    public function getOutstandingLoanAttribute(): float
    {
        return (float) $this->loans()
            ->whereIn('status', ['outstanding', 'partially_paid'])
            ->sum('remaining_amount');
    }
}
