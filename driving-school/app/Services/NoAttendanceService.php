<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Days a student has no attendance record for — counted, never written.
 *
 * The register holds what the school actually did: a check-in, a transfer, a
 * teacher marking somebody absent. A day nobody wrote anything down for is not
 * a record, and inventing one would put rows in the register that no human
 * event produced. So "No Attendance" is arithmetic done at the moment somebody
 * looks:
 *
 *     no-attendance days = finished days since the student started
 *                        − days that actually have a record
 *
 * Nothing here writes to any table. There is no nightly job, no cron, and no
 * migration behind it; turning the page away and back recomputes it.
 *
 * Note what it is NOT. A real `absent` row is the school saying a student was
 * expected and did not come. No Attendance is the school having said nothing at
 * all. A day with an absent row therefore never counts here — a record exists —
 * and it goes on showing as Absent in the ordinary attendance history.
 */
class NoAttendanceService
{
    /**
     * The window a student is counted over: from the day they started, to the
     * last day that has finished. Today is excluded — it is not over, so a
     * student who has not come in yet has not missed it.
     *
     * Both ends may be narrowed by a filter, and `greatest`/`least` keep the
     * student's own start date and the last finished day as hard limits, so no
     * filter can make the window reach before a student enrolled or into today.
     */

    /** The last day that has finished, in the school's own timezone. */
    public function lastFinishedDay(): Carbon
    {
        return Carbon::today()->subDay();
    }

    /**
     * Active students with at least one day nobody recorded, most missed first.
     *
     * One query. `missing_days` and `last_attendance` are computed in SQL as
     * correlated sub-selects rather than a query per student, and the count
     * never touches a row per date: it is the length of the window less the
     * number of dates that have a record in it.
     *
     * @param  array{search?:string, instructor_id?:int|string, min_days?:int|string, date_from?:string, date_to?:string}  $filters
     * @return Builder<Student>
     */
    public function studentsQuery(array $filters = []): Builder
    {
        $end = $this->lastFinishedDay()->toDateString();
        $from = $filters['date_from'] ?? '1970-01-01';
        $to = $filters['date_to'] ?? $end;

        // Days in the window, floored at zero for a student whose window has
        // not opened yet. Inclusive of both ends, hence the + 1.
        $windowStart = 'greatest(students.start_date, ?)';   // binds: $from
        $windowEnd = 'least(?, ?)';                          // binds: $end, $to

        $expected = "greatest(datediff({$windowEnd}, {$windowStart}) + 1, 0)";
        $expectedBindings = [$end, $to, $from];

        // Dates inside the window that have a record — any record, whatever it
        // says. A soft-deleted row is not one: somebody removed it.
        $recorded = "(select count(distinct a.attendance_date) from attendance a
                where a.student_id = students.id
                  and a.deleted_at is null
                  and a.attendance_date between {$windowStart} and {$windowEnd})";
        $recordedBindings = [$from, $end, $to];

        $missing = "greatest({$expected} - {$recorded}, 0)";

        // In the order the placeholders appear, which is why each fragment
        // carries its own bindings rather than one list for the whole string.
        $bindings = [...$expectedBindings, ...$recordedBindings];

        return Student::query()
            ->with('currentInstructor')
            ->where('students.status', 'active')
            ->select('students.*')
            ->selectRaw("{$missing} as missing_days", $bindings)
            ->addSelect(['last_attendance' => Attendance::query()
                ->selectRaw('max(attendance_date)')
                ->whereColumn('attendance.student_id', 'students.id')
                ->whereNull('attendance.deleted_at'),
            ])
            ->whereRaw("{$missing} >= ?", [...$bindings, max(1, (int) ($filters['min_days'] ?? 1))])
            ->when(filled($filters['instructor_id'] ?? null),
                fn (Builder $q) => $q->where('students.current_instructor_id', $filters['instructor_id']))
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters) {
                $term = '%'.trim((string) $filters['search']).'%';

                $q->where(fn (Builder $sub) => $sub
                    ->where('students.full_name', 'like', $term)
                    ->orWhere('students.student_number', 'like', $term)
                    ->orWhere('students.phone', 'like', $term));
            })
            ->orderByDesc('missing_days')
            ->orderBy('students.full_name');
    }

    /**
     * How many active students have missed at least one finished day — the
     * dashboard's number, and the same query the page lists, so the figure
     * somebody clicks and the rows they land on are the same people.
     */
    public function dashboardCount(array $filters = []): int
    {
        return $this->studentsQuery($filters)->count();
    }

    /**
     * The dates one student has no record for.
     *
     * Walks their window a day at a time in PHP, which is cheap — a course is
     * weeks, not years — against one query for the dates they do have.
     *
     * @return Collection<int, Carbon>
     */
    public function missingDaysFor(Student $student, array $filters = []): Collection
    {
        return $this->missingDaysForMany(new EloquentCollection([$student]), $filters)
            ->get($student->id, collect());
    }

    /**
     * The same, for a page of students, in one query rather than one each.
     *
     * @param  EloquentCollection<int, Student>  $students
     * @return Collection<int, Collection<int, Carbon>> keyed by student id
     */
    public function missingDaysForMany(EloquentCollection $students, array $filters = []): Collection
    {
        if ($students->isEmpty()) {
            return collect();
        }

        $end = $this->lastFinishedDay();
        $windowEnd = isset($filters['date_to'])
            ? Carbon::parse($filters['date_to'])->min($end)
            : $end;
        $windowFrom = isset($filters['date_from']) ? Carbon::parse($filters['date_from']) : null;

        $recorded = Attendance::query()
            ->whereIn('student_id', $students->pluck('id'))
            ->whereDate('attendance_date', '<=', $windowEnd->toDateString())
            ->get(['student_id', 'attendance_date'])
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows
                ->map(fn (Attendance $a) => Carbon::parse($a->attendance_date)->toDateString())
                ->unique()
                ->flip());

        return $students->mapWithKeys(function (Student $student) use ($recorded, $windowFrom, $windowEnd) {
            $start = Carbon::parse($student->start_date)->startOfDay();

            if ($windowFrom && $windowFrom->greaterThan($start)) {
                $start = $windowFrom->copy()->startOfDay();
            }

            $has = $recorded->get($student->id, collect());
            $missing = collect();

            for ($day = $start->copy(); $day->lessThanOrEqualTo($windowEnd); $day->addDay()) {
                if (! $has->has($day->toDateString())) {
                    $missing->push($day->copy());
                }
            }

            return [$student->id => $missing];
        });
    }
}
