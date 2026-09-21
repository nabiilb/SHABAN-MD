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

    /**
     * Removing a waiting student from the line.
     *
     * Scoped deliberately: being allowed to TRAIN anybody is not being allowed
     * to CANCEL anybody's work. A teacher may remove an entry their own console
     * holds — one they were named on, or one they added — and an admin may
     * remove any. Another instructor's active queue entry is theirs to manage.
     *
     * Only before training starts. Once a session exists the entry is closed
     * through the session's own cancellation, so timestamps and history stay
     * consistent.
     */
    public function removeFromQueue(User $user, TrainingQueueEntry $entry): bool
    {
        if ($entry->status !== TrainingQueueEntry::WAITING) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isInstructor() || $user->instructorId() === null) {
            return false;
        }

        return $entry->preferred_instructor_id === $user->instructorId()
            || $entry->created_by === $user->id;
    }

    /** Taking the next student into training. */
    public function claim(User $user, TrainingQueueEntry $entry): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && $user->instructorId() !== null);
    }
}
