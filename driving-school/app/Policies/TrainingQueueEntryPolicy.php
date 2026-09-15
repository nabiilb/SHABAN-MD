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

    /** Adding an entry with the admin's full controls — preferences, notes. */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Putting a student in today's line. A teacher needs this to run their own
     * console; it does not let them reorder, move or remove anybody.
     */
    public function addToQueue(User $user): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && $user->instructorId() !== null);
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
