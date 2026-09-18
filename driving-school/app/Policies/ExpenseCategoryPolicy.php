<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;

/**
 * Company finance and system data are admin-only. Instructors and students
 * never pass any of these checks.
 */
class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ExpenseCategory $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ExpenseCategory $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ExpenseCategory $model): bool
    {
        return $user->isAdmin();
    }
}
