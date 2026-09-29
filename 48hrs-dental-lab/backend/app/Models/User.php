<?php

namespace App\Models;

use App\Domain\Permissions;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Notifications\Notifiable;

class User extends LabModel implements AuthenticatableContract, AuthorizableContract, CanResetPasswordContract
{
    use Authenticatable, Authorizable, CanResetPassword, Notifiable;

    protected $hidden = ['password'];

    /** @var list<string>|null permissions resolved once per request */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'failed_logins' => 'integer',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_key', 'key');
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function technician(): HasOne
    {
        return $this->hasOne(Technician::class);
    }

    /** Current permissions of the user's role (a locked role — Super Admin — always holds the whole catalogue). */
    public function permissions(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }
        $role = $this->role()->first();
        if (! $role) {
            return $this->permissionCache = [];
        }

        return $this->permissionCache = $role->locked
            ? Permissions::allKeys()
            : Permissions::ordered(RolePermission::where('role_key', $role->key)->pluck('permission_key')->all());
    }

    public function hasPermission(string|array $permission, string $mode = 'all'): bool
    {
        return Permissions::has($this->permissions(), $permission, $mode);
    }

    /** The shared-rules actor shape used by Workflow::canPerformAction / canViewCase. */
    public function actor(): array
    {
        return ['user' => ['id' => $this->id, 'role' => $this->role_key, 'clinicId' => $this->clinic_id, 'technicianId' => $this->technicianId()], 'permissions' => $this->permissions()];
    }

    public function technicianId(): ?string
    {
        return $this->relationLoaded('technician') ? $this->technician?->id : Technician::where('user_id', $this->id)->value('id');
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /** No "remember me": sessions have an absolute end. */
    public function getRememberTokenName()
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function forgetPermissions(): void
    {
        $this->permissionCache = null;
    }
}
