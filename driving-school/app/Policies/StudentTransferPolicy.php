<?php

namespace App\Policies;

use App\Models\StudentTransfer;
use App\Models\User;

class StudentTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function view(User $user, StudentTransfer $transfer): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isInstructor()) {
            $id = $user->instructorId();

            return $transfer->from_instructor_id === $id || $transfer->to_instructor_id === $id;
        }

        if ($user->isStudent()) {
            return $transfer->student_id === $user->studentId();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function delete(User $user, StudentTransfer $transfer): bool
    {
        return $user->isAdmin();
    }
}
