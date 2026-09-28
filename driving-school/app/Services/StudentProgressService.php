<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StudentProgressService
{
    /**
     * Recomputes a student's progress from the attendance ledger and promotes
     * him to "completed" once the required training days are reached.
     *
     * completed_days = the EFFECTIVE figure the screens show — real attendance
     *                  for an ordinary student, course-length-less-remaining
     *                  for one carrying an imported opening balance
     * remaining      = max(required - completed, 0), or the opening balance
     *                  less training since it was taken
     * progress       = required > 0 ? (required - remaining) / required * 100 : 0
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

    /**
     * Corrects the days a student has left, as the teacher knows them.
     *
     * The register's figure can simply be wrong — a day trained before the
     * school kept records, a day counted twice — and the instructor at the
     * console is the person who knows. There is no "remaining" column to set:
     * remaining is calculated, and the only honest way to change the answer is
     * to change what it is calculated from.
     *
     * So the opening balance is used exactly as the register import uses it.
     * The baseline moves to today and the balance becomes the figure the
     * teacher typed, which makes today's reading theirs and leaves every day
     * trained from here on counting down from it normally. Days already
     * trained today still count against the balance, so the balance is set
     * high enough for the figure on screen to be the one that was typed.
     *
     * Nothing is invented to make the number fit: no attendance is written,
     * the course length is untouched, and the student's status is left exactly
     * as it was — correcting a balance is not a decision about whether
     * somebody has finished.
     *
     * Two screens call this and there is one calculation behind both: the
     * instructor's queue dialog and the admin's student form. They differ only
     * in what they are allowed to do about a student the school has finished
     * with — an instructor cannot reopen one, and an administrator correcting
     * the record deliberately can, because leaving somebody marked finished
     * with five days left is a worse record than either.
     *
     * @param  string  $source  where the correction came from, for the audit trail
     * @param  bool  $mayReopenCompleted  whether a finished student may be set back to active
     *
     * @throws InvalidArgumentException when the figure is not one this course can have
     */
    public function correctRemaining(
        Student $student,
        int $remaining,
        User $actor,
        string $source = 'training queue correction',
        bool $mayReopenCompleted = false,
    ): Student {
        $required = (int) $student->required_training_days;

        if ($remaining < 0) {
            throw new InvalidArgumentException(__('Remaining days cannot be negative.'));
        }

        if ($remaining > $required) {
            throw new InvalidArgumentException(__('Remaining days cannot be more than the :count day course.', [
                'count' => $required,
            ]));
        }

        return DB::transaction(function () use ($student, $remaining, $actor, $source, $mayReopenCompleted) {
            $student = Student::query()->lockForUpdate()->findOrFail($student->getKey());

            $original = $student->getOriginal();
            $before = $student->remaining_days;
            $beforeStatus = $student->status;
            $baseline = today()->toDateString();

            // A finished student reads as nought remaining whatever the balance
            // says, so a correction to a positive figure would be stored and
            // never shown. Rather than leave that quietly impossible, the
            // student is set back to active — but only where the caller has the
            // standing to do it.
            $reopening = $remaining > 0
                && $student->hasCompletedTraining()
                && $mayReopenCompleted;

            $trainedSince = (int) Attendance::query()
                ->where('student_id', $student->getKey())
                ->where('status', 'present')
                ->whereDate('attendance_date', '>=', $baseline)
                ->distinct()
                ->count('attendance_date');

            $student->forceFill([
                'opening_remaining_days' => $remaining + $trainedSince,
                'opening_remaining_from' => $baseline,
            ] + ($reopening ? ['status' => Student::ACTIVE, 'completion_date' => null] : []))->save();

            $student->refresh();

            AuditLogger::log(
                'student.remaining_corrected',
                $student,
                __(':name — remaining days corrected from :before to :after by :actor (:source)', [
                    'name' => $student->full_name,
                    'before' => $before,
                    'after' => $student->remaining_days,
                    'actor' => $actor->name,
                    'source' => $source,
                ]).($reopening ? ' '.__('Reopened from completed to active.') : ''),
                [
                    'remaining_days' => $before,
                    'opening_remaining_days' => $original['opening_remaining_days'] ?? null,
                    'opening_remaining_from' => $original['opening_remaining_from'] ?? null,
                ] + ($reopening ? ['status' => $beforeStatus] : []),
                [
                    'remaining_days' => $student->remaining_days,
                    'opening_remaining_days' => $student->opening_remaining_days,
                    'opening_remaining_from' => $student->opening_remaining_from?->toDateString(),
                    'source' => $source,
                ] + ($reopening ? ['status' => $student->status] : []),
            );

            return $student;
        });
    }

    public function summarise(Student $student): array
    {
        return [
            'required_days' => (int) $student->required_training_days,
            'completed_days' => $student->effective_completed_days,
            'remaining_days' => $student->remaining_days,
            'progress' => $student->progress_percentage,
        ];
    }
}
