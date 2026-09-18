<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use HasFactory, SoftDeletes;

    public const PERFORMANCES = ['excellent', 'very_good', 'good', 'average', 'needs_improvement', 'poor'];

    public const STATUSES = ['scheduled', 'completed', 'cancelled'];

    protected $fillable = [
        'student_id',
        'instructor_id',
        'vehicle_id',
        'lesson_topic_id',
        'lesson_date',
        'topic',
        'performance',
        'duration_minutes',
        'notes',
        'status',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'lesson_date' => 'date',
            'duration_minutes' => 'integer',
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

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function lessonTopic(): BelongsTo
    {
        return $this->belongsTo(LessonTopic::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The attendance day this lesson was taught on, when it came from one. */
    public function attendance(): HasOne
    {
        return $this->hasOne(Attendance::class);
    }

    /**
     * A lesson belongs to the instructor who taught it, exactly like the
     * attendance day it hangs off. Handing a day over moves that day's lesson
     * with it and leaves every other day where it is.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isInstructor()) {
            return $query->where('lessons.instructor_id', $user->instructorId() ?? 0);
        }

        if ($user->isStudent()) {
            return $query->where('lessons.student_id', $user->studentId() ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }
}
