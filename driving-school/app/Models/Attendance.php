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
        'transferred_from_instructor_id',
        'student_transfer_id',
        'attendance_date',
        'check_in_time',
        'status',
        'notes',
        'transferred_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'transferred_at' => 'datetime',
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

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The instructor this row was moved away from, when it was transferred. */
    public function transferredFromInstructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'transferred_from_instructor_id');
    }

    public function studentTransfer(): BelongsTo
    {
        return $this->belongsTo(StudentTransfer::class, 'student_transfer_id');
    }

    /**
     * Attendance belongs to the instructor recorded on the row, which makes
     * ownership date-specific: transferring a student moves only that date's
     * row, so the previous instructor keeps every earlier day and the new one
     * sees nothing before the transfer. A student sees only his own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isInstructor()) {
            return $query->where('attendance.instructor_id', $user->instructorId() ?? 0);
        }

        if ($user->isStudent()) {
            return $query->where('attendance.student_id', $user->studentId() ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }
}
