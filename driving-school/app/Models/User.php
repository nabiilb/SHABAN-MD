<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'role_id',
        'name',
        'email',
        'phone',
        'locale',
        'is_active',
        'password',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function instructor(): HasOne
    {
        return $this->hasOne(Instructor::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /* ----------------------------------------------------------------
     | Role helpers
     | ---------------------------------------------------------------- */

    public function hasRole(string ...$names): bool
    {
        return in_array($this->role?->name, $names, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::ADMIN);
    }

    public function isInstructor(): bool
    {
        return $this->hasRole(Role::INSTRUCTOR);
    }

    public function isStudent(): bool
    {
        return $this->hasRole(Role::STUDENT);
    }

    /**
     * The instructor record bound to the authenticated user.
     * This is the ONLY trusted source of an instructor id — never take it
     * from the request payload.
     */
    public function instructorId(): ?int
    {
        return $this->isInstructor() ? $this->instructor?->id : null;
    }

    public function studentId(): ?int
    {
        return $this->isStudent() ? $this->student?->id : null;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->role?->permissions->contains('name', $permission) ?? false;
    }

    public function homeRoute(): string
    {
        return match ($this->role?->name) {
            Role::ADMIN => 'admin.dashboard',
            Role::INSTRUCTOR => 'instructor.dashboard',
            Role::STUDENT => 'student.dashboard',
            default => 'login',
        };
    }
}
