<?php

namespace App\Policies;

use App\Models\FuelRecord;
use App\Models\User;

/**
 * Fuel is company money. An instructor may record a fill-up for one of their
 * own vehicles, which waits as pending; approving it — and with it the expense
 * or debt it posts — stays with the admin, as does editing and deleting. An instructor may
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

    /**
     * An instructor may record a fill-up, but only for a vehicle of theirs —
     * the request checks the vehicle itself, since the id is what decides it.
     * Their submission waits as pending and posts nothing to the ledger until
     * an admin approves it.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && $user->instructorId() !== null);
    }

    /** Releasing a submission into the company ledger is the admin's call. */
    public function approve(User $user, FuelRecord $model): bool
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
