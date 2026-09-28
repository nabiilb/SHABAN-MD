<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\Student;
use App\Models\TrainingEvaluation;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\NoAttendanceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Days nobody wrote anything down for.
 *
 * The register holds what the school actually did. A day with no row in it is
 * not a record of absence — it is the absence of a record, and the difference
 * matters: a student the school marked absent was expected and did not come; a
 * student with no row at all was never written about either way.
 *
 * So this is counted, not written. No row is created for it, there is no
 * nightly job behind it, and turning the page away and back recomputes it.
 */
class NoAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'Africa/Mogadishu';

    private string $originalTimezone;

    private User $admin;

    private User $teacherUser;

    private Instructor $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        $this->seedReferenceData();

        config(['app.timezone' => self::TIMEZONE]);
        date_default_timezone_set(self::TIMEZONE);
        Carbon::setTestNow(Carbon::parse('2026-09-19 09:00:00', self::TIMEZONE));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->teacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Abdullahi']);
        $this->instructor = $this->makeInstructor('Abdullahi', $this->teacherUser);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        config(['app.timezone' => $this->originalTimezone]);
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    private function service(): NoAttendanceService
    {
        return app(NoAttendanceService::class);
    }

    private function student(string $name, string $startDate, ?Instructor $instructor = null, string $status = 'active'): Student
    {
        $student = $this->makeStudent($name, $instructor ?? $this->instructor, ['start_date' => $startDate]);
        $student->forceFill(['status' => $status])->save();

        return $student->refresh();
    }

    private function attend(Student $student, string $date, string $status = 'present'): Attendance
    {
        return Attendance::create([
            'student_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'attendance_date' => $date,
            'status' => $status,
            'recorded_by' => $this->admin->id,
        ]);
    }

    /** @return array<int, string> the missing dates, as d/m/Y-free ISO strings. */
    private function missingFor(Student $student): array
    {
        return $this->service()->missingDaysFor($student)->map->toDateString()->all();
    }

    /* ================================================================
     | The calculation — the brief's own worked example
     | ================================================================ */

    /** 1 + 3 + 5 + 6 + 7 + 8 + 9 — the example from section 7, exactly. */
    public function test_the_worked_example(): void
    {
        $student = $this->student('Ahmed Ali', '2026-09-15');

        $this->attend($student, '2026-09-15');
        $this->attend($student, '2026-09-18');

        // 16th and 17th have no record. The 14th is before they started, and
        // the 19th is today, which is not finished.
        $this->assertSame(['2026-09-16', '2026-09-17'], $this->missingFor($student));
        $this->assertSame(2, (int) $this->service()->studentsQuery()->find($student->id)->missing_days);

        $this->assertNotContains('2026-09-14', $this->missingFor($student), 'Before they enrolled.');
        $this->assertNotContains('2026-09-19', $this->missingFor($student), 'Today is not over.');
        $this->assertNotContains('2026-09-20', $this->missingFor($student), 'Tomorrow has not happened.');
    }

    /** 3 + 4 — the count is the number of days, whatever that number is. */
    public function test_the_count_matches_the_days(): void
    {
        $three = $this->student('Missing Three', '2026-09-15');
        $this->attend($three, '2026-09-15');
        $this->attend($three, '2026-09-18');

        $five = $this->student('Missing Five', '2026-09-13');
        $this->attend($five, '2026-09-13');

        $this->assertCount(2, $this->service()->missingDaysFor($three));
        $this->assertCount(5, $this->service()->missingDaysFor($five));
        $this->assertSame(
            ['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18'],
            $this->missingFor($five),
        );

        $rows = $this->service()->studentsQuery()->get()->keyBy('full_name');
        $this->assertSame(2, (int) $rows['Missing Three']->missing_days);
        $this->assertSame(5, (int) $rows['Missing Five']->missing_days);
    }

    /** 9 + 10 + 11 — a record of ANY kind is a record. */
    public function test_any_recorded_status_stops_the_day_counting(): void
    {
        foreach (['present', 'absent', 'excused', 'cancelled'] as $status) {
            $student = $this->student("Recorded {$status}", '2026-09-18');
            $this->attend($student, '2026-09-18', $status);

            $this->assertSame(
                [],
                $this->missingFor($student),
                "A day recorded as {$status} is not a day with no attendance.",
            );
        }

        // Including the real `absent` row, which is a different thing entirely
        // and still reads as Absent in the ordinary register.
        $this->assertSame(1, Attendance::where('status', 'absent')->count());
        $this->assertSame(0, $this->service()->dashboardCount());
    }

    /** A removed record leaves the day uncounted again — nobody stands behind it. */
    public function test_a_soft_deleted_record_leaves_the_day_missing(): void
    {
        $student = $this->student('Removed Record', '2026-09-18');
        $this->attend($student, '2026-09-18')->delete();

        $this->assertSame(['2026-09-18'], $this->missingFor($student));
    }

    /** A student who started today has no finished day yet. */
    public function test_a_student_who_started_today_has_nothing_missing(): void
    {
        $student = $this->student('Started Today', '2026-09-19');

        $this->assertSame([], $this->missingFor($student));
        $this->assertNull($this->service()->studentsQuery()->find($student->id));
    }

    /* ================================================================
     | 12 + 13 + 14 — only active students
     | ================================================================ */

    public function test_only_active_students_are_counted(): void
    {
        $active = $this->student('Still Training', '2026-09-15');

        foreach (['completed', 'cancelled', 'suspended'] as $status) {
            $this->student("Not active {$status}", '2026-09-15', null, $status);
        }

        $names = $this->service()->studentsQuery()->pluck('full_name')->all();

        $this->assertSame(['Still Training'], $names);
        $this->assertSame(1, $this->service()->dashboardCount());
        $this->assertSame($active->id, $this->service()->studentsQuery()->first()->id);
    }

    /** Completing a student today drops them off the list, and keeps their history. */
    public function test_completing_a_student_removes_them_but_keeps_their_records(): void
    {
        $student = $this->student('Finishing Today', '2026-09-15');
        $this->attend($student, '2026-09-15');

        $this->assertSame(1, $this->service()->dashboardCount());

        $student->update(['status' => 'completed']);

        $this->assertSame(0, $this->service()->dashboardCount());
        $this->assertSame(1, Attendance::where('student_id', $student->id)->count(), 'History is untouched.');
    }

    /* ================================================================
     | 2 + 21 + 22 — nothing is ever written
     | ================================================================ */

    public function test_nothing_creates_an_attendance_row(): void
    {
        $student = $this->student('Ahmed Ali', '2026-09-10');
        $existing = $this->attend($student, '2026-09-12');
        $before = $existing->fresh()->getAttributes();

        // Every path that touches the calculation, several times over.
        foreach (range(1, 3) as $_) {
            $this->service()->dashboardCount();
            $this->service()->studentsQuery()->get();
            $this->service()->missingDaysFor($student);
            $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
            $this->actingAs($this->admin)->get(route('admin.students.no-attendance'))->assertOk();
        }

        $this->assertSame(1, Attendance::count(), 'The calculation writes nothing.');
        $this->assertSame($before, $existing->fresh()->getAttributes(), 'And changes nothing.');
        $this->assertSame(0, Attendance::where('status', 'absent')->count());
    }

    /** 20 — and there is no scheduled job that would. */
    public function test_no_automatic_absence_command_or_schedule_remains(): void
    {
        $this->assertArrayNotHasKey(
            'attendance:mark-absent',
            Artisan::all(),
            'The automatic absence command must be gone.',
        );

        $scheduled = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command)
            ->implode(' ');

        $this->assertStringNotContainsString('mark-absent', $scheduled);
        $this->assertStringContainsString('training:expire-stale', $scheduled, 'The stale-cycle schedule stays.');
    }

    /* ================================================================
     | 15 + 16 + 17 + 18 — the dashboard and the page
     | ================================================================ */

    /** 15 — the card's number is the page's rows. */
    public function test_the_dashboard_count_equals_the_list(): void
    {
        $this->student('Missing One', '2026-09-18');
        $this->student('Missing Many', '2026-09-10');
        $upToDate = $this->student('Up To Date', '2026-09-18');
        $this->attend($upToDate, '2026-09-18');

        $metrics = app(DashboardService::class)->adminMetrics();
        $page = $this->actingAs($this->admin)->get(route('admin.students.no-attendance'))->assertOk();

        $this->assertSame(2, $metrics['no_attendance_students']);
        $this->assertSame($metrics['no_attendance_students'], $page->viewData('total'));
        $this->assertSame($metrics['no_attendance_students'], $page->viewData('students')->count());
        $this->assertNotContains('Up To Date', $page->viewData('students')->pluck('full_name')->all());
    }

    public function test_the_dashboard_card_links_to_the_page(): void
    {
        $this->student('Missing One', '2026-09-18');

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('No Attendance')
            ->assertSee(route('admin.students.no-attendance'), false);
    }

    /** 18 — most missed first. */
    public function test_the_list_is_ordered_by_the_most_missed(): void
    {
        $this->student('Missing Two', '2026-09-16');
        $this->student('Missing Eight', '2026-09-10');
        $this->student('Missing Four', '2026-09-14');

        $this->assertSame(
            ['Missing Eight', 'Missing Four', 'Missing Two'],
            $this->service()->studentsQuery()->pluck('full_name')->all(),
        );
    }

    /** 16 + 17 — the instructor, or an honest Unassigned. */
    public function test_the_instructor_is_shown_or_named_unassigned(): void
    {
        $assigned = $this->student('Has A Teacher', '2026-09-18');
        $orphan = $this->makeStudent('No Teacher', null, ['start_date' => '2026-09-18']);

        $page = $this->actingAs($this->admin)->get(route('admin.students.no-attendance'))->assertOk();

        $this->assertSame(
            'Abdullahi',
            $page->viewData('students')->firstWhere('id', $assigned->id)->currentInstructor?->full_name,
        );
        $this->assertNull($page->viewData('students')->firstWhere('id', $orphan->id)->currentInstructor);

        $page->assertSee('Abdullahi')->assertSee('Unassigned');
    }

    /** The page shows the dates, not only the count. */
    public function test_the_page_shows_which_dates_are_missing(): void
    {
        $student = $this->student('Ahmed Ali', '2026-09-15');
        $this->attend($student, '2026-09-15');
        $this->attend($student, '2026-09-18');

        $page = $this->actingAs($this->admin)->get(route('admin.students.no-attendance'))->assertOk();

        $this->assertSame(
            ['2026-09-16', '2026-09-17'],
            $page->viewData('missingDates')->get($student->id)->map->toDateString()->all(),
        );

        // The two missing dates are on the page. (The 15th appears too — as
        // their start date — so its presence proves nothing either way.)
        $page->assertSee('16/09/2026')->assertSee('17/09/2026')->assertSee('2 days');
    }

    /** The last attendance column reports their most recent record. */
    public function test_the_last_attendance_is_reported(): void
    {
        $student = $this->student('Ahmed Ali', '2026-09-15');
        $this->attend($student, '2026-09-16');
        $never = $this->student('Never Came', '2026-09-15');

        $rows = $this->service()->studentsQuery()->get()->keyBy('id');

        $this->assertSame('2026-09-16', Carbon::parse($rows[$student->id]->last_attendance)->toDateString());
        $this->assertNull($rows[$never->id]->last_attendance);
    }

    /* ================================================================
     | 12 (filters)
     | ================================================================ */

    public function test_the_filters_narrow_the_same_calculation(): void
    {
        $mine = $this->student('Ahmed Ali', '2026-09-10');
        $other = $this->makeInstructor('Mohamed');
        $theirs = $this->student('Amina Omar', '2026-09-16', $other);

        $service = $this->service();

        // By instructor.
        $this->assertSame(['Ahmed Ali'], $service->studentsQuery(['instructor_id' => $this->instructor->id])->pluck('full_name')->all());
        $this->assertSame(['Amina Omar'], $service->studentsQuery(['instructor_id' => $other->id])->pluck('full_name')->all());

        // By search — name, number and phone alike.
        $this->assertSame(['Amina Omar'], $service->studentsQuery(['search' => 'Amina'])->pluck('full_name')->all());
        $this->assertSame(['Ahmed Ali'], $service->studentsQuery(['search' => $mine->student_number])->pluck('full_name')->all());
        $this->assertSame(['Amina Omar'], $service->studentsQuery(['search' => $theirs->phone])->pluck('full_name')->all());

        // By how many days are missing: Ahmed has 8, Amina 2.
        $this->assertSame(['Ahmed Ali'], $service->studentsQuery(['min_days' => 5])->pluck('full_name')->all());
        $this->assertCount(2, $service->studentsQuery(['min_days' => 1])->get());

        // And the count follows the same filters, so the header cannot lie.
        $this->assertSame(1, $service->dashboardCount(['min_days' => 5]));
    }

    /** A date range narrows the window without reaching before a student started. */
    public function test_a_date_range_narrows_the_window(): void
    {
        $student = $this->student('Ahmed Ali', '2026-09-15');

        $filters = ['date_from' => '2026-09-16', 'date_to' => '2026-09-17'];

        $this->assertSame(2, (int) $this->service()->studentsQuery($filters)->find($student->id)->missing_days);
        $this->assertSame(
            ['2026-09-16', '2026-09-17'],
            $this->service()->missingDaysFor($student, $filters)->map->toDateString()->all(),
        );

        // A range that reaches before they enrolled still starts at their own
        // start date, and one reaching into today still stops at yesterday —
        // the 18th, since today is the 19th. So the window is the 15th to the
        // 18th, and all four days are missing.
        $wide = ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'];

        $this->assertSame(4, (int) $this->service()->studentsQuery($wide)->find($student->id)->missing_days);
        $this->assertSame(
            ['2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18'],
            $this->service()->missingDaysFor($student, $wide)->map->toDateString()->all(),
        );
    }

    /* ================================================================
     | 19 — training progress is untouched
     | ================================================================ */

    public function test_no_attendance_days_do_not_touch_training_progress(): void
    {
        $student = $this->student('Ahmed Ali', '2026-09-10');
        $student->update(['required_training_days' => 30]);
        $this->attend($student, '2026-09-12');
        $student->refresh();

        $before = [
            'completed' => $student->completed_days,
            'remaining' => $student->remaining_days,
            'progress' => $student->progress_percentage,
        ];

        // Eight finished days with no record, and not one of them counts.
        $this->assertGreaterThan(0, $this->service()->missingDaysFor($student)->count());
        $this->service()->dashboardCount();
        $this->actingAs($this->admin)->get(route('admin.students.no-attendance'))->assertOk();

        $student->refresh();

        $this->assertSame($before['completed'], $student->completed_days);
        $this->assertSame($before['remaining'], $student->remaining_days);
        $this->assertSame($before['progress'], $student->progress_percentage);
        $this->assertSame(1, $student->completed_days, 'Only the one present day.');

        // And none of the training tables gained anything.
        $this->assertSame(0, TrainingSession::count());
        $this->assertSame(0, TrainingEvaluation::count());
        $this->assertSame(0, Lesson::count());
    }

    /** The page is the admin's. */
    public function test_an_instructor_cannot_open_the_page(): void
    {
        $this->actingAs($this->teacherUser)->get(route('admin.students.no-attendance'))->assertForbidden();
    }
}
