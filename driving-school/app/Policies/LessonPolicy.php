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
            return $lesson->instructor_id === $user->instructorId()
                || $lesson->student?->current_instructor_id === $user->instructorId();
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

    public function update(User $user, Lesson $lesson): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isInstructor()
            && $lesson->instructor_id === $user->instructorId()
            && $lesson->student?->current_instructor_id === $user->instructorId();
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->update($user, $lesson);
    }
}
