<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Company finance and system data are admin-only. Instructors and students
 * never pass any of these checks.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AuditLog $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, AuditLog $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, AuditLog $model): bool
    {
        return $user->isAdmin();
    }
}
