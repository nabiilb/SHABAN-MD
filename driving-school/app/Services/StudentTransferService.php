<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\StudentInstructorAssignment;
use App\Models\StudentTransfer;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StudentTransferService
{
    /**
     * Moves a student to another instructor.
     *
     * The historical assignment row is closed (never deleted) and a new
     * current one opened, so the student's full instructor history survives.
     *
     * The transfer is tied to a date: the attendance already recorded for that
     * date moves with the student, and every earlier day stays exactly where it
     * is. Transferring today therefore hands over today's attendance only.
     */
    public function transfer(
        Student $student,
        Instructor $toInstructor,
        string $reason,
        User $actor,
        ?CarbonInterface $date = null,
        ?string $notes = null,
    ): StudentTransfer {
        $date = $date ? Carbon::parse($date) : Carbon::today();

        if ($student->current_instructor_id === $toInstructor->id) {
            throw new RuntimeException(__('The student is already assigned to this instructor.'));
        }

        return DB::transaction(function () use ($student, $toInstructor, $reason, $actor, $date, $notes) {
            $fromInstructorId = $student->current_instructor_id;

            // Close the running assignment — keep the record for history.
            StudentInstructorAssignment::query()
                ->where('student_id', $student->id)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'assigned_to' => $date->copy()->subDay()->toDateString(),
                    'updated_at' => now(),
                ]);

            StudentInstructorAssignment::create([
                'student_id' => $student->id,
                'instructor_id' => $toInstructor->id,
                'assigned_from' => $date->toDateString(),
                'assigned_to' => null,
                'is_current' => true,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);

            $student->forceFill(['current_instructor_id' => $toInstructor->id])->save();

            $transfer = StudentTransfer::create([
                'student_id' => $student->id,
                'from_instructor_id' => $fromInstructorId,
                'to_instructor_id' => $toInstructor->id,
                'transfer_date' => $date->toDateString(),
                'reason' => $reason,
                'notes' => $notes,
                'transferred_by' => $actor->id,
            ]);

            $movedAttendance = $this->moveAttendanceForDate($student, $toInstructor, $date, $transfer);

            // The lesson taught that day belongs to that day's attendance, so
            // it moves with it.
            $movedLessons = Lesson::query()
                ->where('student_id', $student->id)
                ->whereDate('lesson_date', $date->toDateString())
                ->where('instructor_id', '!=', $toInstructor->id)
                ->update(['instructor_id' => $toInstructor->id, 'updated_at' => now()]);

            AuditLogger::log(
                'student.transferred',
                $student,
                "Transferred {$student->full_name} to {$toInstructor->full_name}",
                ['current_instructor_id' => $fromInstructorId],
                [
                    'current_instructor_id' => $toInstructor->id,
                    'reason' => $reason,
                    'transfer_date' => $date->toDateString(),
                    'attendance_records_moved' => $movedAttendance,
                    'lessons_moved' => $movedLessons,
                ],
            );

            return $transfer;
        });
    }

    /**
     * Re-points the student's attendance for the transfer date at the new
     * instructor, and only that date.
     *
     * Existing rows are updated in place rather than copied, so the student
     * still appears exactly once for the day and no duplicate is created.
     * Earlier dates are never touched: they keep the instructor who taught
     * them, which is what makes the previous instructor's history survive.
     *
     * @return int how many attendance rows moved
     */
    protected function moveAttendanceForDate(
        Student $student,
        Instructor $toInstructor,
        CarbonInterface $date,
        StudentTransfer $transfer,
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
                'student_transfer_id' => $transfer->id,
                'transferred_at' => now(),
            ])->save();

            AuditLogger::log(
                'attendance.transferred',
                $record,
                __("Moved :student's attendance for :date to :instructor", [
                    'student' => $student->full_name,
                    'date' => $date->toDateString(),
                    'instructor' => $toInstructor->full_name,
                ]),
                ['instructor_id' => $record->transferred_from_instructor_id],
                ['instructor_id' => $toInstructor->id],
            );
        }

        return $records->count();
    }

    /** Opens the very first assignment row when a student is registered. */
    public function assignInitial(Student $student, ?int $instructorId, ?User $actor = null): void
    {
        if (! $instructorId) {
            return;
        }

        StudentInstructorAssignment::create([
            'student_id' => $student->id,
            'instructor_id' => $instructorId,
            'assigned_from' => $student->start_date?->toDateString() ?? now()->toDateString(),
            'is_current' => true,
            'reason' => 'Initial assignment',
            'created_by' => $actor?->id,
        ]);
    }
}
