<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingEvaluation extends Model
{
    use HasFactory;

    /** The ratings a teacher can give, best first. */
    public const RATINGS = ['excellent', 'very_good', 'good', 'needs_improvement'];

    protected $fillable = [
        'training_session_id',
        'student_id',
        'instructor_id',
        'attendance_id',
        'attendance_status',
        'evaluation',
        'comment',
        'evaluated_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['evaluated_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
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
            return $query->where('training_evaluations.instructor_id', $user->instructorId() ?? 0);
        }

        if ($user->isStudent()) {
            return $query->where('training_evaluations.student_id', $user->studentId() ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }

    public function getRatingLabelAttribute(): string
    {
        return $this->evaluation
            ? __(ucwords(str_replace('_', ' ', $this->evaluation)))
            : __('Not rated');
    }
}
