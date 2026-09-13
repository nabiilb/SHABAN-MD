<?php

namespace App\Policies;

use App\Models\StudentPayment;
use App\Models\User;

class StudentPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStudent();
    }

    public function view(User $user, StudentPayment $payment): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isStudent() && $payment->student_id === $user->studentId();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, StudentPayment $payment): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, StudentPayment $payment): bool
    {
        return $user->isAdmin();
    }
}
