<?php

namespace App\Policies;

use App\Models\CompanyDebt;
use App\Models\User;

/**
 * Company finance and system data are admin-only. Instructors and students
 * never pass any of these checks.
 */
class CompanyDebtPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, CompanyDebt $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, CompanyDebt $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, CompanyDebt $model): bool
    {
        return $user->isAdmin();
    }
}
