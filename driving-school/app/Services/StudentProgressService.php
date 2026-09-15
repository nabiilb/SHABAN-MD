<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Student;

class StudentProgressService
{
    /**
     * Recomputes a student's progress from the attendance ledger and promotes
     * him to "completed" once the required training days are reached.
     *
     * completed_days = distinct dates marked present
     * remaining      = max(required - completed, 0)
     * progress       = required > 0 ? completed / required * 100 : 0
     */
    public function recalculate(Student $student): Student
    {
        $presentDates = Attendance::query()
            ->where('student_id', $student->id)
            ->where('status', 'present')
            ->orderBy('attendance_date')
            ->distinct()
            ->pluck('attendance_date');

        $completed = $presentDates->count();
        $required = (int) $student->required_training_days;

        if ($required > 0 && $completed >= $required) {
            // The completion date is the date of the final qualifying day.
            $qualifying = $presentDates->get($required - 1);

            $student->forceFill([
                'status' => in_array($student->status, ['cancelled', 'suspended'], true)
                    ? $student->status
                    : 'completed',
                'completion_date' => $qualifying,
            ]);
        } elseif ($student->status === 'completed') {
            // Attendance was removed/edited below the requirement — reopen.
            $student->forceFill([
                'status' => 'active',
                'completion_date' => null,
            ]);
        }

        if ($student->isDirty()) {
            $student->save();
        }

        return $student->refresh();
    }

    public function summarise(Student $student): array
    {
        return [
            'required_days' => (int) $student->required_training_days,
            'completed_days' => $student->completed_days,
            'remaining_days' => $student->remaining_days,
            'progress' => $student->progress_percentage,
        ];
    }
}
