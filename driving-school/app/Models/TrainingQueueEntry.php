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

    /** Whether this student is the teacher's own, or here on a transfer. */
    public function ownershipOn(?int $instructorId = null): string
    {
        $permanent = $this->student?->current_instructor_id;

        if ($instructorId === null) {
            $instructorId = $this->student?->instructorIdOn($this->queue_date);
        }

        return $permanent === $instructorId ? 'permanent' : 'transferred';
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

        return max(0, $this->joined_at?->diffInMinutes(now()) ?? 0);
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
