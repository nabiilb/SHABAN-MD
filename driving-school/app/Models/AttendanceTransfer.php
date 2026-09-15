<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who owns a student's attendance on one particular date.
 *
 * This is deliberately narrower than a StudentTransfer: it hands over a single
 * day and leaves the student's permanent instructor alone, so the day after,
 * the student is back on their usual instructor's list without anyone doing
 * anything.
 */
class AttendanceTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'attendance_date',
        'from_instructor_id',
        'to_instructor_id',
        'reason',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['attendance_date' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromInstructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'from_instructor_id');
    }

    public function toInstructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'to_instructor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function scopeOn(Builder $query, $date): Builder
    {
        return $query->whereDate('attendance_date', $date);
    }

    /** An instructor sees only hand-overs they were a party to. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isInstructor()) {
            $instructorId = $user->instructorId() ?? 0;

            return $query->where(fn (Builder $q) => $q
                ->where('from_instructor_id', $instructorId)
                ->orWhere('to_instructor_id', $instructorId));
        }

        if ($user->isStudent()) {
            return $query->where('student_id', $user->studentId() ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }
}
