<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;

class LessonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'instructor', 'student');
    }

    public function view(User $user, Lesson $lesson): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isInstructor()) {
            // The instructor who taught it, which keeps a handed-over day with
            // the instructor who actually took it.
            return $lesson->instructor_id === $user->instructorId();
        }

        if ($user->isStudent()) {
            return $lesson->student_id === $user->studentId();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    /**
     * Editing needs the lesson to still be yours for that date, so a day you
     * have handed on becomes read-only history rather than something either
     * instructor can rewrite.
     */
    public function update(User $user, Lesson $lesson): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isInstructor()
            && $lesson->instructor_id === $user->instructorId()
            && $lesson->student?->instructorIdOn($lesson->lesson_date) === $user->instructorId();
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->update($user, $lesson);
    }
}
