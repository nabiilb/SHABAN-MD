<?php

namespace App\Models;

use App\Support\PhoneNumber;
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

    /** The status that declares a student's training finished. */
    public const COMPLETED = 'completed';

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
        'opening_remaining_days',
        'opening_remaining_from',
    ];

    /**
     * active_phone_key is a guard column, not data: it carries the student's
     * normalised phone number while they are an active student and NULL once
     * they are not, and the unique index over it is what makes "two active
     * students cannot share a phone number" a guarantee the database keeps
     * rather than one the form hopes for. A double-clicked registration is
     * refused by the index even when both requests pass validation, because
     * neither had committed when the other looked.
     *
     * Derived here, on every save, so no caller can change a phone or a status
     * and forget it. `phone` itself is never rewritten — what the admin typed
     * is what is stored and shown.
     *
     * A row that was already not holding its key — one of the pre-existing
     * duplicates the migration reported — goes on not holding it, so those rows
     * stay editable instead of turning every future save into a 1062. That
     * grandfathering is deliberately narrow: it only applies to a row that is
     * already on the books, whose stored key is already NULL, and whose phone,
     * status and deleted_at this save does not touch. A new student always
     * claims their key, which is what makes the index refuse the second half of
     * a double-clicked registration.
     */
    protected static function booted(): void
    {
        static::saving(function (self $student): void {
            $student->active_phone_key = $student->claimablePhoneKey();
        });

        // SoftDeletes writes deleted_at with its own query rather than a model
        // save, so `saving` never fires for it: a removed student would keep
        // holding their number and could never be registered again.
        static::deleted(function (self $student): void {
            if ($student->isForceDeleting()) {
                return;
            }

            static::withoutEvents(fn () => static::withTrashed()
                ->whereKey($student->getKey())
                ->update(['active_phone_key' => null]));
        });
    }

    /**
     * The key this student may hold: their normalised number while they are
     * active and undeleted, and null when they are not — or when another active
     * student is already holding it.
     */
    protected function claimablePhoneKey(): ?string
    {
        if ($this->status !== 'active' || $this->deleted_at !== null) {
            return null;
        }

        $key = PhoneNumber::normalize($this->phone);

        if ($key === null) {
            return null;
        }

        // Grandfathered: an existing row that already holds no key, being saved
        // for some reason other than its number, its status or its deletion.
        // Everything else claims, and lets the unique index decide.
        $grandfathered = $this->exists
            && $this->getRawOriginal('active_phone_key') === null
            && ! $this->isDirty(['phone', 'status', 'deleted_at']);

        return $grandfathered ? null : $key;
    }

    /**
     * The active student holding this number, whichever way it was written.
     * The one question the registration form asks before accepting a phone.
     */
    public static function activeWithPhone(mixed $phone, ?int $ignoreId = null): ?self
    {
        $key = PhoneNumber::normalize($phone);

        if ($key === null) {
            return null;
        }

        return static::query()
            ->where('status', 'active')
            ->where('active_phone_key', $key)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'start_date' => 'date',
            'completion_date' => 'date',
            'required_training_days' => 'integer',
            'opening_remaining_days' => 'integer',
            'opening_remaining_from' => 'date',
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

    /**
     * Students nobody is responsible for on a date.
     *
     * That means no permanent instructor, or one who is no longer active, and
     * no hand-over to an active teacher for the date either. They are the open
     * pool: every teacher sees them and any teacher may take them, which is
     * what keeps a queued student from being counted by the admin board while
     * appearing on nobody's console.
     */
    public function scopeUnassignedOn(Builder $query, $date): Builder
    {
        $date = Carbon::parse($date)->toDateString();

        return $query->where(function (Builder $q) use ($date) {
            // Handed over for the day: it comes down to who received them.
            $q->whereHas('attendanceTransfers', fn (Builder $t) => $t
                ->whereDate('attendance_date', $date)
                ->whereDoesntHave('toInstructor', fn (Builder $i) => $i->where('status', 'active')))
                // Otherwise, to their permanent instructor.
                ->orWhere(fn (Builder $own) => $own
                    ->whereDoesntHave('attendanceTransfers', fn (Builder $t) => $t->whereDate('attendance_date', $date))
                    ->whereDoesntHave('currentInstructor', fn (Builder $i) => $i->where('status', 'active')));
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

    /**
     * Whether the school has declared this student's training finished.
     *
     * THE rule behind the two accessors below. A completed student has nothing
     * left to train and is 100% through, whatever the attendance ledger says —
     * the status is the school's own statement about the student, and it wins
     * over a count of rows.
     *
     * This is not a cosmetic override. The register import brings in students
     * the school finished with years ago and carries no attendance for them, so
     * counting days would report a student the school considers done as 0%
     * complete with a full course still to run. The same happens whenever an
     * admin marks somebody complete by hand — for a transfer credit, a retest,
     * or a course finished before this system existed.
     */
    public function hasCompletedTraining(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /**
     * Whether this student arrived with an opening balance of training days
     * rather than a full attendance history — a register import.
     */
    public function hasOpeningBalance(): bool
    {
        return $this->opening_remaining_days !== null;
    }

    /**
     * Training days recorded since the opening balance was taken.
     *
     * Only days on or after `opening_remaining_from` count: everything before
     * that date is already represented by the opening number itself, so
     * counting it again would take the same training off twice.
     */
    public function getTrainingDaysSinceOpeningAttribute(): int
    {
        if (! $this->hasOpeningBalance() || ! $this->opening_remaining_from) {
            return 0;
        }

        return (int) $this->attendance()
            ->where('status', 'present')
            ->whereDate('attendance_date', '>=', $this->opening_remaining_from->toDateString())
            ->distinct()
            ->count('attendance_date');
    }

    /**
     * Training days still to run — never money. The financial balance is
     * `balance`, is derived from payments alone, and is untouched by this:
     * a student who has finished training may still owe the whole fee.
     *
     * Three sources, in order of authority:
     *
     *   1. A completed status. The school has said the student is finished.
     *   2. An opening balance, less whatever they have trained since it was
     *      taken. This is the register import: the school knew how many days
     *      were left, and this application has no attendance from before then.
     *   3. Otherwise the ordinary count — the course length less the days
     *      attended — which is every student registered through the app.
     */
    public function getRemainingDaysAttribute(): int
    {
        if ($this->hasCompletedTraining()) {
            return 0;
        }

        if ($this->hasOpeningBalance()) {
            return max((int) $this->opening_remaining_days - $this->training_days_since_opening, 0);
        }

        return max($this->required_training_days - $this->completed_days, 0);
    }

    /**
     * The "Completed" figure the screens show — one rule for every student.
     *
     * The course length, less the days still to run, and never more than the
     * course itself. A five-day course cannot be nine days completed: somebody
     * who attended nine reads 5 of 5 and 100%, which is what the progress bar
     * beside it already said. Where the days still to run come from is the
     * student's own business — real attendance for an ordinary student, the
     * register's opening balance for an imported one, nothing at all for one
     * the school has marked finished — and all three arrive here the same way.
     *
     * A DISPLAY figure, derived from the remaining count. It is not a claim
     * about how many attendance rows exist: `completed_days` still holds the
     * real count, every attendance row is still on file, and the register and
     * the training history go on showing what actually happened.
     */
    public function getEffectiveCompletedDaysAttribute(): int
    {
        $required = (int) $this->required_training_days;

        // No course length to be a proportion of — and nothing to divide by.
        if ($required <= 0) {
            return 0;
        }

        return min(max($required - $this->remaining_days, 0), $required);
    }

    /**
     * How far through the course the student is, from the days still to run.
     *
     * One formula for both kinds of student: for an ordinary student
     * `required - remaining` is exactly the days they have attended, so this is
     * the same number the old calculation produced; for an imported one it is
     * the days the register says are behind them.
     */
    public function getProgressPercentageAttribute(): float
    {
        if ($this->hasCompletedTraining()) {
            return 100.0;
        }

        $required = (int) $this->required_training_days;

        if ($required <= 0) {
            return 0.0;
        }

        $done = $required - $this->remaining_days;

        // Clamped at both ends: a student cannot be less than 0% or more than
        // 100% through their course, however the two numbers were arrived at —
        // an opening balance larger than the course length included.
        return round(min(max($done / $required * 100, 0), 100), 1);
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
