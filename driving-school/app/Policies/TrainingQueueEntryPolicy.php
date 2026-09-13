<?php

namespace App\Policies;

use App\Models\TrainingQueueEntry;
use App\Models\User;

/**
 * Teachers read the queue and claim from it; only admins rearrange it.
 */
class TrainingQueueEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'instructor');
    }

    public function view(User $user, TrainingQueueEntry $entry): bool
    {
        return $user->hasRole('admin', 'instructor');
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Reordering, moving and editing the line is an admin job. */
    public function update(User $user, TrainingQueueEntry $entry): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, TrainingQueueEntry $entry): bool
    {
        return $user->isAdmin();
    }

    /** Taking the next student into training. */
    public function claim(User $user, TrainingQueueEntry $entry): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && $user->instructorId() !== null);
    }
}
