<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    /** An instructor sees only the vehicles assigned to him. */
    public function view(User $user, Vehicle $vehicle): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isInstructor() && $vehicle->instructor_id === $user->instructorId();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $user->isAdmin();
    }
}
