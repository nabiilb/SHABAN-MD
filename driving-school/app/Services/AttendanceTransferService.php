<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceTransfer;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hands a student's attendance for ONE date to another instructor.
 *
 * This deliberately does not touch the student's permanent instructor: the
 * hand-over is recorded against the date, so the next day the student is back
 * on their usual instructor's list with nothing to undo. What moves is that
 * date's attendance row and the lesson taught on it — never an earlier or a
 * later day.
 */
class AttendanceTransferService
{
    public function transfer(
        Student $student,
        Instructor $toInstructor,
        CarbonInterface $date,
        User $actor,
        ?string $reason = null,
        ?string $notes = null,
    ): AttendanceTransfer {
        $date = Carbon::parse($date)->startOfDay();
        $currentOwner = $student->instructorIdOn($date);

        if ($currentOwner === $toInstructor->id) {
            throw new RuntimeException(__('The student is already assigned to this instructor.'));
        }

        if ($toInstructor->status !== 'active') {
            throw new RuntimeException(__('The receiving instructor is not active.'));
        }

        return DB::transaction(function () use ($student, $toInstructor, $date, $actor, $reason, $notes, $currentOwner) {
            // One hand-over per student per day: re-transferring the same day
            // updates the existing row rather than stacking another on top.
            $handover = AttendanceTransfer::updateOrCreate(
                ['student_id' => $student->id, 'attendance_date' => $date->toDateString()],
                [
                    'from_instructor_id' => $currentOwner,
                    'to_instructor_id' => $toInstructor->id,
                    'reason' => $reason,
                    'notes' => $notes,
                    'created_by' => $actor->id,
                ],
            );

            $movedAttendance = $this->moveAttendance($student, $toInstructor, $date, $handover);
            $movedLessons = $this->moveLessons($student, $toInstructor, $date);

            AuditLogger::log(
                'attendance.transferred',
                $handover,
                __("Moved :student's :date attendance to :instructor", [
                    'student' => $student->full_name,
                    'date' => $date->toDateString(),
                    'instructor' => $toInstructor->full_name,
                ]),
                ['instructor_id' => $currentOwner],
                [
                    'instructor_id' => $toInstructor->id,
                    'attendance_date' => $date->toDateString(),
                    'attendance_records_moved' => $movedAttendance,
                    'lessons_moved' => $movedLessons,
                ],
            );

            return $handover;
        });
    }

    /**
     * Re-points that date's attendance at the new instructor. Rows are updated
     * in place, never copied, so the student still appears exactly once for the
     * day and no duplicate can appear.
     */
    protected function moveAttendance(
        Student $student,
        Instructor $toInstructor,
        CarbonInterface $date,
        AttendanceTransfer $handover,
    ): int {
        $records = Attendance::query()
            ->where('student_id', $student->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->where('instructor_id', '!=', $toInstructor->id)
            ->lockForUpdate()
            ->get();

        foreach ($records as $record) {
            $record->forceFill([
                'transferred_from_instructor_id' => $record->instructor_id,
                'instructor_id' => $toInstructor->id,
                'attendance_transfer_id' => $handover->id,
                'transferred_at' => now(),
            ])->save();
        }

        return $records->count();
    }

    /**
     * The lesson taught that day belongs to that day's attendance, so it moves
     * with it — carrying its topic and performance rating to the new
     * instructor untouched. Other dates' lessons are never considered.
     */
    protected function moveLessons(Student $student, Instructor $toInstructor, CarbonInterface $date): int
    {
        return Lesson::query()
            ->where('student_id', $student->id)
            ->whereDate('lesson_date', $date->toDateString())
            ->where('instructor_id', '!=', $toInstructor->id)
            ->update(['instructor_id' => $toInstructor->id, 'updated_at' => now()]);
    }
}
