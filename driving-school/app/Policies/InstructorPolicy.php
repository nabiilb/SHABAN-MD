<?php

namespace App\Policies;

use App\Models\Instructor;
use App\Models\User;

class InstructorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Instructor $instructor): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isInstructor()) {
            return $instructor->id === $user->instructorId();
        }

        if ($user->isStudent()) {
            return $instructor->id === $user->student?->current_instructor_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Instructor $instructor): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Instructor $instructor): bool
    {
        return $user->isAdmin();
    }
}
