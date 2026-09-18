<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Closing off a day's register.
 *
 * The school's register is kept by check-in: a student who trains gets a row,
 * and a student who does not gets nothing at all. That leaves "did not attend"
 * and "nobody wrote anything down" looking identical, which is no use for
 * counting a student's progress or answering why they are behind.
 *
 * So once a day is over, every active student with no row for it gets one,
 * marked absent. Once the day is OVER — never the day in progress, because a
 * student who has not arrived by lunchtime has not missed the day yet.
 */
class DailyAbsenceService
{
    /**
     * Marks the day's absences and returns what happened.
     *
     * Idempotent by construction. A student who already has ANY row for the
     * date is skipped, whatever it says: present, absent, excused or cancelled
     * are all somebody's record of that day and none of them is overwritten.
     * Running it twice for the same date writes nothing the second time.
     *
     * @return array{date:string, created:int, skipped:int, students:int}
     */
    public function markAbsent($date, ?User $actor = null): array
    {
        $date = Carbon::parse($date)->startOfDay();

        if ($date->isToday() || $date->isFuture()) {
            throw new RuntimeException(__(
                'Only a day that has finished can be marked absent — :date has not.',
                ['date' => $date->format('d/m/Y')],
            ));
        }

        $day = $date->toDateString();
        $students = $this->studentsForDay($day);

        // withTrashed: a soft-deleted row is still somebody's decision about
        // that day, and writing over it would resurrect the day as a new one.
        $alreadyRecorded = Attendance::withTrashed()
            ->whereDate('attendance_date', $day)
            ->whereIn('student_id', $students->pluck('id'))
            ->pluck('student_id')
            ->flip();

        $created = 0;

        foreach ($students as $student) {
            if ($alreadyRecorded->has($student->id)) {
                continue;
            }

            $created += $this->recordAbsence($student, $day, $actor) ? 1 : 0;
        }

        return [
            'date' => $day,
            'created' => $created,
            'skipped' => $students->count() - $created,
            'students' => $students->count(),
        ];
    }

    /**
     * The students whose register this day belongs to.
     *
     * Active, and enrolled by then — somebody who joined afterwards was not
     * absent from a day they were not yet a student for.
     *
     * A student carrying an imported opening balance is held back until they
     * have actually turned up. The register import brings in people the school
     * was teaching months ago, with no attendance in this system and a
     * remaining-days figure that already accounts for everything they did
     * before; marking them absent every night would fill the register with days
     * they were never expected at, for students who may never come back. Once
     * one real attendance record exists from the opening date onwards, they are
     * being taught here and join the ordinary rule.
     *
     * (An absence written by this command cannot be what opens that gate: none
     * can exist before the gate opens. A soft-deleted record does not open it
     * either — somebody removed it.)
     *
     * @return Collection<int, Student>
     */
    protected function studentsForDay(string $day)
    {
        $students = Student::query()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $day)
            ->orderBy('id')
            ->get(['id', 'current_instructor_id', 'opening_remaining_days', 'opening_remaining_from']);

        $waiting = $students->filter(
            fn (Student $student) => $student->opening_remaining_days !== null
                && $student->opening_remaining_from !== null,
        );

        if ($waiting->isEmpty()) {
            return $students;
        }

        // The latest day each of them has any record for. One query, then the
        // comparison per student, because the threshold is each student's own.
        $latest = Attendance::query()
            ->whereIn('student_id', $waiting->pluck('id'))
            ->selectRaw('student_id, max(attendance_date) as last_date')
            ->groupBy('student_id')
            ->pluck('last_date', 'student_id');

        return $students->reject(function (Student $student) use ($waiting, $latest) {
            if (! $waiting->contains('id', $student->id)) {
                return false;
            }

            $last = $latest->get($student->id);

            return $last === null
                || Carbon::parse($last)->lt($student->opening_remaining_from);
        })->values();
    }

    /**
     * One absence, written only if the student still has no row for the day.
     *
     * The check is repeated inside the transaction so two runs starting at the
     * same moment — a cron firing while somebody presses the button — cannot
     * both write one.
     */
    protected function recordAbsence(Student $student, string $day, ?User $actor): bool
    {
        return DB::transaction(function () use ($student, $day, $actor) {
            $exists = Attendance::withTrashed()
                ->where('student_id', $student->id)
                ->whereDate('attendance_date', $day)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return false;
            }

            Attendance::create([
                'student_id' => $student->id,
                // Whoever they belong to, or nobody — an absence has no teacher
                // and a great many students have no permanent instructor.
                'instructor_id' => $student->current_instructor_id,
                'attendance_date' => $day,
                'status' => 'absent',
                'notes' => __('Marked absent automatically — no attendance was recorded for this day.'),
                'recorded_by' => $actor?->id,
            ]);

            return true;
        });
    }

    /**
     * How many absences a real run would write, writing none of them.
     *
     * @return array{date:string, would_create:int, students:int}
     */
    public function preview($date): array
    {
        $date = Carbon::parse($date)->startOfDay();

        if ($date->isToday() || $date->isFuture()) {
            throw new RuntimeException(__(
                'Only a day that has finished can be marked absent — :date has not.',
                ['date' => $date->format('d/m/Y')],
            ));
        }

        $day = $date->toDateString();

        // The same eligibility a real run uses, so the dry run cannot promise
        // a different number from the one that would actually be written.
        $students = $this->studentsForDay($day)->pluck('id');

        $recorded = Attendance::withTrashed()
            ->whereDate('attendance_date', $day)
            ->whereIn('student_id', $students)
            ->distinct()
            ->count('student_id');

        return [
            'date' => $day,
            'would_create' => max($students->count() - $recorded, 0),
            'students' => $students->count(),
        ];
    }

    /** The day the scheduled run closes off: yesterday, in the school's own time. */
    public function defaultDate(): Carbon
    {
        return Carbon::today()->subDay();
    }
}
