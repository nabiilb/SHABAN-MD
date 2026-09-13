<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class TrainingQueueEntry extends Model
{
    use HasFactory;

    public const WAITING = 'waiting';

    public const TRAINING_IN_PROGRESS = 'training_in_progress';

    public const ATTENDANCE_PENDING = 'attendance_pending';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::WAITING,
        self::TRAINING_IN_PROGRESS,
        self::ATTENDANCE_PENDING,
        self::COMPLETED,
        self::CANCELLED,
    ];

    /** Statuses that still occupy a place in the line. */
    public const OPEN_STATUSES = [self::WAITING, self::TRAINING_IN_PROGRESS, self::ATTENDANCE_PENDING];

    protected $fillable = [
        'student_id',
        'queue_date',
        'position',
        'status',
        'preferred_instructor_id',
        'assigned_duration_minutes',
        'joined_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'queue_date' => 'date',
            'joined_at' => 'datetime',
            'position' => 'integer',
            'assigned_duration_minutes' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function preferredInstructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'preferred_instructor_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ----------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------- */

    public function scopeForDate(Builder $query, $date = null): Builder
    {
        return $query->whereDate('queue_date', Carbon::parse($date ?? today())->toDateString());
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::WAITING);
    }

    public function scopeInLine(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * FIFO: whoever joined the line first trains first. `position` only breaks
     * ties, and is what an admin's manual reorder writes to.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('joined_at')->orderBy('id');
    }

    /**
     * The entries one teacher may take on a date — the single rule both
     * dashboards ask, so their queues cannot drift apart.
     *
     * An entry belongs to a teacher's line when that teacher was asked for by
     * name, or owns the student for the date. An entry nobody owns for the date
     * belongs to every teacher's line: somebody has to be able to take them, or
     * the admin board counts a waiting student that no console will ever show.
     */
    public function scopeClaimableBy(Builder $query, ?int $instructorId, $date = null): Builder
    {
        $instructorId ??= 0;
        $date = Carbon::parse($date ?? today())->toDateString();

        $wanted = fn (Builder $q) => $q->whereHas(
            'preferredInstructor',
            fn (Builder $i) => $i->where('status', 'active'),
        );

        $unwanted = fn (Builder $q) => $q->whereDoesntHave(
            'preferredInstructor',
            fn (Builder $i) => $i->where('status', 'active'),
        );

        return $query->where(function (Builder $q) use ($instructorId, $date, $wanted, $unwanted) {
            // Asked for by name — a preference for a teacher who has left is
            // no preference at all.
            $q->where(fn (Builder $preferred) => $wanted($preferred)->where('preferred_instructor_id', $instructorId))
                // Nobody asked for, and this teacher owns them for the date.
                ->orWhere(fn (Builder $own) => $unwanted($own)
                    ->whereHas('student', fn (Builder $s) => $s->ownedByInstructorOn($instructorId, $date)))
                // Nobody owns them for the date either: open to whoever is free.
                ->orWhere(fn (Builder $open) => $unwanted($open)
                    ->whereHas('student', fn (Builder $s) => $s->unassignedOn($date)));
        });
    }

    /**
     * The same rule, asked of one entry — used when a teacher actually claims a
     * student, so the check and the list can never disagree.
     */
    public function isClaimableBy(?int $instructorId): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->claimableBy($instructorId, $this->queue_date)
            ->exists();
    }

    /**
     * Whether this student is the teacher's own, here on a transfer, or in the
     * open pool that belongs to nobody.
     */
    public function ownershipOn(?int $instructorId = null): string
    {
        $owner = $this->student?->instructorIdOn($this->queue_date);

        if ($owner === null || ! Instructor::whereKey($owner)->where('status', 'active')->exists()) {
            return 'unassigned';
        }

        $instructorId ??= $owner;

        return $this->student?->current_instructor_id === $instructorId ? 'permanent' : 'transferred';
    }

    /* ----------------------------------------------------------------
     | Presentation
     | ---------------------------------------------------------------- */

    /** How long this student has been waiting, in whole minutes. */
    public function getWaitingMinutesAttribute(): int
    {
        if ($this->status !== self::WAITING) {
            return 0;
        }

        return max(0, (int) ($this->joined_at?->diffInMinutes(now()) ?? 0));
    }

    public function getStatusLabelAttribute(): string
    {
        return __(match ($this->status) {
            self::WAITING => 'Waiting',
            self::TRAINING_IN_PROGRESS => 'Training in Progress',
            self::ATTENDANCE_PENDING => 'Attendance Pending',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            default => ucfirst($this->status),
        });
    }

    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            self::WAITING => '🟡',
            self::TRAINING_IN_PROGRESS => '🟢',
            self::ATTENDANCE_PENDING => '🔵',
            self::COMPLETED => '✅',
            self::CANCELLED => '🔴',
            default => '⚪',
        };
    }
}
