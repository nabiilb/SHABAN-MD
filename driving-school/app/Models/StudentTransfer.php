<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'from_instructor_id',
        'to_instructor_id',
        'transfer_date',
        'reason',
        'notes',
        'transferred_by',
    ];

    protected function casts(): array
    {
        return ['transfer_date' => 'date'];
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

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    /** An instructor sees only transfers he was a party to. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isInstructor()) {
            $instructorId = $user->instructorId() ?? 0;

            return $query->where(function (Builder $q) use ($instructorId) {
                $q->where('from_instructor_id', $instructorId)
                    ->orWhere('to_instructor_id', $instructorId);
            });
        }

        if ($user->isStudent()) {
            return $query->where('student_transfers.student_id', $user->studentId() ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }
}
