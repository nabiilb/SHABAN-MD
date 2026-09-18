<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
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

        // Students who joined after the day in question were not absent from
        // it; they were not students yet.
        $students = Student::query()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $day)
            ->orderBy('id')
            ->get(['id', 'current_instructor_id']);

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

        $students = Student::query()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $day)
            ->pluck('id');

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
