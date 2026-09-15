<?php

namespace App\Policies;

use App\Models\InstructorLoan;
use App\Models\User;

class InstructorLoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    /** An instructor can read his OWN loan and no one else's. */
    public function view(User $user, InstructorLoan $loan): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isInstructor() && $loan->instructor_id === $user->instructorId();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, InstructorLoan $loan): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, InstructorLoan $loan): bool
    {
        return $user->isAdmin();
    }

    /** Only admin registers repayments — instructors are read-only here. */
    public function recordPayment(User $user, InstructorLoan $loan): bool
    {
        return $user->isAdmin();
    }
}
