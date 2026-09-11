<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single training session.
 *
 * The countdown is derived from started_at / expected_end_at, which live in the
 * database — the browser only renders what the server says, so refreshing the
 * page or opening a second screen cannot make the clock drift.
 */
class TrainingSession extends Model
{
    use HasFactory, SoftDeletes;

    public const IN_PROGRESS = 'in_progress';

    public const PAUSED = 'paused';

    public const ATTENDANCE_PENDING = 'attendance_pending';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::IN_PROGRESS, self::PAUSED, self::ATTENDANCE_PENDING, self::COMPLETED, self::CANCELLED];

    /** Statuses in which a student is considered to be occupying a teacher. */
    public const LIVE_STATUSES = [self::IN_PROGRESS, self::PAUSED, self::ATTENDANCE_PENDING];

    /** Durations a teacher can pick from when starting. */
    public const DURATION_OPTIONS = [20, 30, 40, 60, 90];

    protected $fillable = [
        'student_id',
        'instructor_id',
        'training_queue_entry_id',
        'lesson_topic_id',
        'vehicle_id',
        'status',
        'assigned_duration_minutes',
        'extended_minutes',
        'started_at',
        'expected_end_at',
        'ended_at',
        'paused_at',
        'paused_seconds',
        'ended_early',
        'notes',
        'created_by',
        'ended_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expected_end_at' => 'datetime',
            'ended_at' => 'datetime',
            'paused_at' => 'datetime',
            'assigned_duration_minutes' => 'integer',
            'extended_minutes' => 'integer',
            'paused_seconds' => 'integer',
            'ended_early' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(TrainingQueueEntry::class, 'training_queue_entry_id');
    }

    public function lessonTopic(): BelongsTo
    {
        return $this->belongsTo(LessonTopic::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function evaluation(): HasOne
    {
        return $this->hasOne(TrainingEvaluation::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /* ----------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------- */

    /** Sessions that still need something done to them. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', [self::IN_PROGRESS, self::PAUSED]);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isInstructor()) {
            return $query->where('training_sessions.instructor_id', $user->instructorId() ?? 0);
        }

        if ($user->isStudent()) {
            return $query->where('training_sessions.student_id', $user->studentId() ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }

    /* ----------------------------------------------------------------
     | The clock
     | ---------------------------------------------------------------- */

    /** Total minutes allowed, including any extensions. */
    public function getTotalMinutesAttribute(): int
    {
        return $this->assigned_duration_minutes + $this->extended_minutes;
    }

    /**
     * Seconds left, from the server's clock. Negative is clamped to zero, and
     * a paused session holds still at the moment it was paused.
     */
    public function getRemainingSecondsAttribute(): int
    {
        if (in_array($this->status, [self::COMPLETED, self::CANCELLED, self::ATTENDANCE_PENDING], true)) {
            return 0;
        }

        $reference = $this->status === self::PAUSED ? $this->paused_at : now();

        return max(0, (int) round($reference->diffInSeconds($this->expected_end_at, false)));
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === self::IN_PROGRESS && $this->remaining_seconds <= 0;
    }

    /** mm:ss for display. */
    public function getRemainingForHumansAttribute(): string
    {
        $seconds = $this->remaining_seconds;

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** How long the session actually ran, in minutes. */
    public function getActualMinutesAttribute(): ?int
    {
        if (! $this->ended_at) {
            return null;
        }

        $seconds = $this->started_at->diffInSeconds($this->ended_at) - $this->paused_seconds;

        return max(0, (int) round($seconds / 60));
    }

    public function getStatusLabelAttribute(): string
    {
        return __(match ($this->status) {
            self::IN_PROGRESS => 'Training in Progress',
            self::PAUSED => 'Paused',
            self::ATTENDANCE_PENDING => 'Attendance Pending',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            default => ucfirst($this->status),
        });
    }

    /** The shape the dashboards and the polling endpoint both consume. */
    public function toBoardArray(): array
    {
        return [
            'id' => $this->id,
            'student' => $this->student?->full_name,
            'student_number' => $this->student?->student_number,
            'instructor' => $this->instructor?->full_name,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'lesson' => $this->lessonTopic?->display_name,
            'assigned_duration_minutes' => $this->assigned_duration_minutes,
            'extended_minutes' => $this->extended_minutes,
            'total_minutes' => $this->total_minutes,
            'remaining_seconds' => $this->remaining_seconds,
            'started_at' => $this->started_at?->toIso8601String(),
            'expected_end_at' => $this->expected_end_at?->toIso8601String(),
            'started_at_human' => $this->started_at?->format('g:i A'),
            'expected_end_at_human' => $this->expected_end_at?->format('g:i A'),
            'ended_at_human' => $this->ended_at?->format('g:i A'),
            'is_overdue' => $this->is_overdue,
        ];
    }
}
