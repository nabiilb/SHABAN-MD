<?php

namespace App\Policies;

use App\Models\CompanyDebt;
use App\Models\User;

/**
 * Raising, editing, paying and cancelling a debt stay with the admin — this is
 * the company's ledger. An instructor may read only the debts that reach them
 * through a vehicle assigned to them or through fuel they took on credit, which
 * is exactly what CompanyDebt::visibleTo() returns, so an id typed into the
 * address bar reaches no further than the page does.
 */
class CompanyDebtPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && $user->instructorId() !== null);
    }

    public function view(User $user, CompanyDebt $model): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isInstructor() || $user->instructorId() === null) {
            return false;
        }

        return CompanyDebt::query()->whereKey($model->getKey())->visibleTo($user)->exists();
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
