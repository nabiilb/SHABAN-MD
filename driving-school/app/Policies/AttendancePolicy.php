<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'instructor', 'student');
    }

    /**
     * Ownership follows the instructor recorded on the row, not the student's
     * current instructor — so a transfer hands over that day only, and the
     * previous instructor keeps every earlier day.
     */
    public function view(User $user, Attendance $attendance): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isInstructor()) {
            return $attendance->instructor_id === $user->instructorId();
        }

        if ($user->isStudent()) {
            return $attendance->student_id === $user->studentId();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    /**
     * Editing additionally requires the student to still be assigned to this
     * instructor, so days that have been transferred away become read-only
     * history rather than something either side can rewrite.
     */
    public function update(User $user, Attendance $attendance): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isInstructor()
            && $attendance->instructor_id === $user->instructorId()
            && $attendance->student?->instructorIdOn($attendance->attendance_date) === $user->instructorId();
    }

    /**
     * Moving a student, and that day's attendance with them, is allowed only
     * for the instructor the student is currently assigned to.
     */
    public function transfer(User $user, Attendance $attendance): bool
    {
        return $attendance->student !== null
            && $user->can('recordFor', [$attendance->student, $attendance->attendance_date]);
    }

    public function delete(User $user, Attendance $attendance): bool
    {
        return $this->update($user, $attendance);
    }
}
