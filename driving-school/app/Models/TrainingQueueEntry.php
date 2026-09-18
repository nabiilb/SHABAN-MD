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

    /**
     * THE definition of an open cycle: a student the school still has
     * something to finish. Every Training Console path asks this same list, so
     * "already in a queue" means the same thing wherever it is asked.
     */
    public const OPEN_STATUSES = [self::WAITING, self::TRAINING_IN_PROGRESS, self::ATTENDANCE_PENDING];

    /** And the two it can end in. Nothing else is terminal. */
    public const TERMINAL_STATUSES = [self::COMPLETED, self::CANCELLED];

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
        'closed_at',
        'closed_by',
        'close_reason',
    ];

    /**
     * active_student_id is a guard column, not data: it carries the student id
     * while the entry is still open and NULL once the cycle is finished, and
     * the unique index over (active_student_id, queue_date) is what makes "one
     * open queue entry per student per day" a guarantee the database keeps
     * rather than one the application promises.
     *
     * Derived here, on every save, so no caller can set a status and forget it.
     * (A plain column rather than GENERATED ALWAYS, which needs MySQL 5.7.6 —
     * this application supports 5.5.)
     */
    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            $entry->active_student_id = in_array($entry->status, self::OPEN_STATUSES, true)
                ? $entry->student_id
                : null;
        });
    }

    protected function casts(): array
    {
        return [
            'queue_date' => 'date',
            'joined_at' => 'datetime',
            'closed_at' => 'datetime',
            'position' => 'integer',
            'assigned_duration_minutes' => 'integer',
            'active_student_id' => 'integer',
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

    /** Who closed the cycle. Null when the twelve-hour rule expired it. */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
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
     * Open cycles, school-wide. Deliberately says nothing about a date: an
     * entry left open yesterday is still open today, and the day boundary must
     * not hide it from the teacher who searches the student tomorrow.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** Whether this cycle is still open. */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * When the twelve hours this cycle is allowed to stay open run out.
     *
     * The clock starts when the CURRENT unresolved state began, not when the
     * row was created — a student who trained for an hour and is now awaiting
     * evaluation has been pending since the session ended, not since they
     * joined the queue this morning:
     *
     *   waiting               joined_at            they have been waiting since
     *   training_in_progress  session started_at   the session has run since
     *   attendance_pending    session ended_at     the evaluation has been due since
     *
     * Falls back to updated_at when a session timestamp is somehow missing, so
     * a malformed row still expires rather than blocking a student for ever.
     */
    public function openedCurrentStateAt(): ?Carbon
    {
        $session = $this->sessions()
            ->whereIn('status', TrainingSession::LIVE_STATUSES)
            ->orderByDesc('started_at')
            ->first();

        return match ($this->status) {
            self::WAITING => $this->joined_at ?? $this->created_at,
            self::TRAINING_IN_PROGRESS => $session?->started_at ?? $this->updated_at,
            self::ATTENDANCE_PENDING => $session?->ended_at ?? $session?->started_at ?? $this->updated_at,
            default => null,
        };
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
     *
     * Turning on the `training_shared_queue` setting drops the ownership rule
     * altogether: one line, every teacher sees all of it, and only a named
     * preferred teacher narrows an entry.
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
            $q->where(fn (Builder $preferred) => $wanted($preferred)->where('preferred_instructor_id', $instructorId));

            // One shared line: every teacher sees every student nobody asked
            // for by name, and takes whoever is next. Off by default, because
            // the per-teacher line is what makes a day's transfer mean
            // anything.
            if (Setting::flag('training_shared_queue')) {
                $q->orWhere(fn (Builder $anyone) => $unwanted($anyone));

                return;
            }

            // Nobody asked for, and this teacher owns them for the date.
            $q->orWhere(fn (Builder $own) => $unwanted($own)
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
     * How this student came to be in the day's line: with their own teacher,
     * handed to someone else for the day, or belonging to nobody.
     *
     * Deliberately says nothing about who is looking. A student who permanently
     * belongs to another teacher is still "permanent" — they are simply not
     * this teacher's — and calling them "transferred" because someone else is
     * reading the board would be a lie, which is what the shared queue used to
     * print.
     */
    public function ownershipOn(): string
    {
        $owner = $this->student?->instructorIdOn($this->queue_date);

        if ($owner === null || ! Instructor::whereKey($owner)->where('status', 'active')->exists()) {
            return 'unassigned';
        }

        return $this->student?->current_instructor_id === $owner ? 'permanent' : 'transferred';
    }

    /** The teacher responsible for this student on the entry's date. */
    public function ownerIdOn(): ?int
    {
        return $this->student?->instructorIdOn($this->queue_date);
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
