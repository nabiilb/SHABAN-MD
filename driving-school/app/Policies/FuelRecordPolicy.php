<?php

namespace App\Policies;

use App\Models\FuelRecord;
use App\Models\User;

/**
 * Fuel is company money, so every write stays with the admin. An instructor may
 * read the fill-ups that are theirs — their own, and those for a vehicle
 * assigned to them — and nothing else; the record-level check below is the same
 * question FuelRecord::visibleTo() asks of the list, so an id typed into the
 * address bar reaches no further than the page does.
 */
class FuelRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && $user->instructorId() !== null);
    }

    public function view(User $user, FuelRecord $model): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isInstructor() || $user->instructorId() === null) {
            return false;
        }

        return FuelRecord::query()->whereKey($model->getKey())->visibleTo($user)->exists();
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
