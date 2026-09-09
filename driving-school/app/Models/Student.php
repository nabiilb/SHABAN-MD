<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Student extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['active', 'completed', 'suspended', 'cancelled'];

    protected $fillable = [
        'student_number',
        'user_id',
        'full_name',
        'phone',
        'email',
        'address',
        'date_of_birth',
        'gender',
        'license_type',
        'start_date',
        'required_training_days',
        'current_instructor_id',
        'status',
        'completion_date',
        'total_fee',
        'notes',
        'profile_photo',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'start_date' => 'date',
            'completion_date' => 'date',
            'required_training_days' => 'integer',
            'total_fee' => 'decimal:2',
        ];
    }

    /* ----------------------------------------------------------------
     | Relationships
     | ---------------------------------------------------------------- */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentInstructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'current_instructor_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(StudentTransfer::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(StudentInstructorAssignment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(StudentPayment::class);
    }

    /** Day-scoped hand-overs of this student's attendance. */
    public function attendanceTransfers(): HasMany
    {
        return $this->hasMany(AttendanceTransfer::class);
    }

    /* ----------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------- */

    /**
     * THE isolation scope. Never accepts an instructor id from the request:
     * it derives it from the authenticated user's instructor relationship.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isInstructor()) {
            return $query->where('students.current_instructor_id', $user->instructorId() ?? 0);
        }

        if ($user->isStudent()) {
            return $query->where('students.id', $user->studentId() ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Students whose attendance an instructor owns on a given date.
     *
     * That is their permanent list for the day, minus anyone handed over to
     * someone else that date, plus anyone handed over to them. Because the
     * override is stored per date, the list falls back to the permanent
     * assignment on any day without a hand-over.
     */
    public function scopeOwnedByInstructorOn(Builder $query, ?int $instructorId, $date): Builder
    {
        $instructorId ??= 0;
        $date = Carbon::parse($date)->toDateString();

        return $query->where(function (Builder $q) use ($instructorId, $date) {
            $q->where(function (Builder $mine) use ($instructorId, $date) {
                $mine->where('students.current_instructor_id', $instructorId)
                    ->whereDoesntHave('attendanceTransfers', fn (Builder $t) => $t->whereDate('attendance_date', $date));
            })->orWhereHas('attendanceTransfers', fn (Builder $t) => $t
                ->whereDate('attendance_date', $date)
                ->where('to_instructor_id', $instructorId));
        });
    }

    /** The instructor responsible for this student on a given date. */
    public function instructorIdOn($date): ?int
    {
        $handover = $this->attendanceTransfers()
            ->whereDate('attendance_date', Carbon::parse($date)->toDateString())
            ->first();

        return $handover?->to_instructor_id ?? $this->current_instructor_id;
    }

    /** Adds a `completed_days` sub-select so lists avoid N+1 progress queries. */
    public function scopeWithProgress(Builder $query): Builder
    {
        return $query->addSelect(['completed_days' => Attendance::query()
            ->selectRaw('count(distinct attendance_date)')
            ->whereColumn('attendance.student_id', 'students.id')
            ->whereNull('attendance.deleted_at')
            ->where('attendance.status', 'present'),
        ]);
    }

    /* ----------------------------------------------------------------
     | Progress
     | ---------------------------------------------------------------- */

    public function getCompletedDaysAttribute($value = null): int
    {
        if (! is_null($value)) {
            return (int) $value;
        }

        if (array_key_exists('completed_days', $this->attributes)) {
            return (int) $this->attributes['completed_days'];
        }

        return (int) $this->attendance()
            ->where('status', 'present')
            ->distinct()
            ->count('attendance_date');
    }

    public function getRemainingDaysAttribute(): int
    {
        return max($this->required_training_days - $this->completed_days, 0);
    }

    public function getProgressPercentageAttribute(): float
    {
        $required = (int) $this->required_training_days;

        if ($required <= 0) {
            return 0.0;
        }

        return round(min($this->completed_days / $required * 100, 100), 1);
    }

    public function isNearCompletion(int $threshold = 80): bool
    {
        return $this->status === 'active' && $this->progress_percentage >= $threshold;
    }

    public function getTotalPaidAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->total_fee - $this->total_paid, 2);
    }
}
