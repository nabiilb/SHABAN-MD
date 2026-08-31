<?php

namespace App\Policies;

use App\Models\FuelRecord;
use App\Models\User;

/**
 * Company finance and system data are admin-only. Instructors and students
 * never pass any of these checks.
 */
class FuelRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, FuelRecord $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, FuelRecord $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, FuelRecord $model): bool
    {
        return $user->isAdmin();
    }
}
