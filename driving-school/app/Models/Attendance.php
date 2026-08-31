<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'attendance';

    public const STATUSES = ['present', 'absent', 'excused', 'cancelled'];

    protected $fillable = [
        'student_id',
        'instructor_id',
        'attendance_date',
        'check_in_time',
        'status',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return ['attendance_date' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * An instructor sees attendance he recorded OR attendance of a student
     * currently assigned to him. A student sees only his own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isInstructor()) {
            $instructorId = $user->instructorId() ?? 0;

            return $query->where(function (Builder $q) use ($instructorId) {
                $q->where('attendance.instructor_id', $instructorId)
                    ->orWhereHas('student', fn (Builder $s) => $s->where('current_instructor_id', $instructorId));
            });
        }

        if ($user->isStudent()) {
            return $query->where('attendance.student_id', $user->studentId() ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }
}
