<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentInstructorAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'instructor_id',
        'assigned_from',
        'assigned_to',
        'is_current',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'assigned_from' => 'date',
            'assigned_to' => 'date',
            'is_current' => 'boolean',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }
}
