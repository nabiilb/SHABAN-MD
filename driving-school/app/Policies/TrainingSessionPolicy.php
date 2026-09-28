<?php

namespace App\Policies;

use App\Models\TrainingSession;
use App\Models\User;

/**
 * Only an admin or the teacher running the session may touch it. A teacher
 * cannot end, extend or evaluate someone else's session even by guessing its id.
 */
class TrainingSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'instructor');
    }

    public function view(User $user, TrainingSession $session): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isInstructor()) {
            return $session->instructor_id === $user->instructorId();
        }

        return $user->isStudent() && $session->student_id === $user->studentId();
    }

    /** Starting a session is a teacher action; admins may do it too. */
    public function create(User $user): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && $user->instructorId() !== null);
    }

    /** End, pause, resume and extend all share this check. */
    public function manage(User $user, TrainingSession $session): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isInstructor() && $session->instructor_id === $user->instructorId();
    }

    public function update(User $user, TrainingSession $session): bool
    {
        return $this->manage($user, $session);
    }

    /** Only the teacher who ran it (or an admin) records the evaluation. */
    public function evaluate(User $user, TrainingSession $session): bool
    {
        return $this->manage($user, $session);
    }

    public function delete(User $user, TrainingSession $session): bool
    {
        return $user->isAdmin();
    }
}
