<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    /**
     * An instructor may only ever see a student CURRENTLY assigned to him.
     * Changing the id in the URL therefore cannot leak another
     * instructor's student — this check runs on every single request.
     */
    public function view(User $user, Student $student): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isInstructor()) {
            return $student->current_instructor_id === $user->instructorId();
        }

        if ($user->isStudent()) {
            return $student->id === $user->studentId();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Student $student): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Student $student): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Student $student): bool
    {
        return $user->isAdmin();
    }

    /** Recording attendance / lessons for a student. */
    public function recordFor(User $user, Student $student): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isInstructor()
            && $student->current_instructor_id === $user->instructorId();
    }

    /** Only the instructor a student currently belongs to may transfer him. */
    public function transfer(User $user, Student $student): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isInstructor()
            && $student->current_instructor_id === $user->instructorId();
    }
}
