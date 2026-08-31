<?php

namespace App\Services;

use App\Models\Instructor;
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

            AuditLogger::log(
                'student.transferred',
                $student,
                "Transferred {$student->full_name} to {$toInstructor->full_name}",
                ['current_instructor_id' => $fromInstructorId],
                ['current_instructor_id' => $toInstructor->id, 'reason' => $reason],
            );

            return $transfer;
        });
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
