<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\TrainingQueueEntry;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\ReportService;
use App\Services\TrainingEligibilityService;
use App\Services\TrainingQueueService;
use App\Support\QueueEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A student the school has finished with is still one of the school's students.
 *
 * They stay in the ordinary Students list, in the reports, and on their
 * instructor's own list, and the dashboard now counts them and links straight
 * to them. What they do NOT do is turn up in anything operational — the waiting
 * queue is for students who are still training.
 */
class CompletedStudentsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacherUser;

    private Instructor $instructor;

    private Student $finished;

    private Student $training;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-16 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->teacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Cabdi Yuusuf']);
        $this->instructor = $this->makeInstructor('Cabdi Yuusuf', $this->teacherUser);

        // Finished, with three real days on file and a fee still owing.
        $this->finished = $this->makeStudent('Sihaam Cali Mumin', $this->instructor, [
            'required_training_days' => 30,
        ]);
        $this->attend($this->finished, 3);
        $this->finished->refresh()->update(['status' => 'completed', 'total_fee' => 100]);
        $this->finished->refresh();

        $this->training = $this->makeStudent('Ubax Abdi Xirsi', $this->instructor, [
            'required_training_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function attend(Student $student, int $days): void
    {
        foreach (range(1, $days) as $offset) {
            Attendance::create([
                'student_id' => $student->id,
                'instructor_id' => $this->instructor->id,
                'attendance_date' => today()->copy()->subDays($offset)->toDateString(),
                'status' => 'present',
                'recorded_by' => $this->admin->id,
            ]);
        }
    }

    /** @return array<int, string> the names the Students list returns. */
    private function listedNames(array $query = []): array
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.students.index', $query))
            ->assertOk()
            ->viewData('students')
            ->pluck('full_name')
            ->all();
    }

    /* ================================================================
     | 1 + 4 — the dashboard
     | ================================================================ */

    /** 1 — counted from the status, not guessed from attendance. */
    public function test_a_completed_student_is_counted_on_the_admin_dashboard(): void
    {
        $metrics = app(DashboardService::class)->adminMetrics();

        $this->assertSame(1, $metrics['completed_students']);
        $this->assertSame(1, $metrics['active_students']);
        $this->assertSame(2, $metrics['total_students']);

        // Three days of a thirty-day course would never make this student
        // count as completed if the number were inferred.
        $this->assertSame(3, $this->finished->completed_days);
    }

    /** The card is on the page, with the number and a way through to them. */
    public function test_the_dashboard_shows_a_completed_students_card(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Completed Students')
            ->assertSee(route('admin.students.index', ['status' => 'completed']), false);
    }

    /** 4 — and the link it carries opens the list already filtered. */
    public function test_the_dashboard_link_opens_the_completed_students_list(): void
    {
        $names = $this->listedNames(['status' => 'completed']);

        $this->assertSame(['Sihaam Cali Mumin'], $names);
        $this->assertNotContains('Ubax Abdi Xirsi', $names);
    }

    /** This month's completions are dated ones only — imports have no date. */
    public function test_this_months_completions_count_only_dated_completions(): void
    {
        $this->assertSame(
            0,
            app(DashboardService::class)->adminMetrics()['completions_this_month'],
            'An imported completion carries no date, so it is not this month.',
        );

        $this->finished->forceFill(['completion_date' => today()])->save();

        $this->assertSame(1, app(DashboardService::class)->adminMetrics()['completions_this_month']);
        $this->assertSame(1, app(DashboardService::class)->adminMetrics()['completed_students']);

        // Last month's completion still counts in the total, not the month.
        $this->finished->forceFill(['completion_date' => today()->copy()->subMonth()])->save();

        $this->assertSame(0, app(DashboardService::class)->adminMetrics()['completions_this_month']);
        $this->assertSame(1, app(DashboardService::class)->adminMetrics()['completed_students']);
    }

    /* ================================================================
     | 2 + 3 + 8 — the Students list
     | ================================================================ */

    /** 2 — no filter at all: everybody, completed included. */
    public function test_a_completed_student_appears_in_the_general_students_list(): void
    {
        $names = $this->listedNames();

        $this->assertContains('Sihaam Cali Mumin', $names);
        $this->assertContains('Ubax Abdi Xirsi', $names);
        $this->assertCount(2, $names);
    }

    /** 8 — and every filter reaches the students it should, and no others. */
    public function test_every_status_filter_returns_the_students_it_should(): void
    {
        $this->assertSame(['Sihaam Cali Mumin'], $this->listedNames(['status' => 'completed']));
        $this->assertSame(['Ubax Abdi Xirsi'], $this->listedNames(['status' => 'active']));
        $this->assertSame([], $this->listedNames(['status' => 'suspended']));
        $this->assertSame([], $this->listedNames(['status' => 'cancelled']));

        // Nothing was hidden or removed by filtering.
        $this->assertSame(2, Student::count());
        $this->assertCount(2, $this->listedNames());
    }

    /** A search finds a completed student like any other. */
    public function test_a_completed_student_is_found_by_search(): void
    {
        $this->assertSame(['Sihaam Cali Mumin'], $this->listedNames(['search' => 'Sihaam']));
        $this->assertSame(['Sihaam Cali Mumin'], $this->listedNames(['search' => $this->finished->student_number]));
        $this->assertSame(
            ['Sihaam Cali Mumin'],
            $this->listedNames(['search' => 'Sihaam', 'status' => 'completed']),
            'Search and the status filter work together.',
        );
    }

    /** And filtering by their instructor still finds them. */
    public function test_a_completed_student_is_found_under_their_instructor(): void
    {
        $this->assertContains(
            'Sihaam Cali Mumin',
            $this->listedNames(['instructor_id' => $this->instructor->id]),
        );
    }

    /* ================================================================
     | 5 + 6 + 7 — what the row says
     | ================================================================ */

    public function test_the_listed_completed_student_reads_as_finished(): void
    {
        $row = $this->actingAs($this->admin)
            ->get(route('admin.students.index', ['status' => 'completed']))
            ->viewData('students')
            ->firstWhere('id', $this->finished->id);

        $this->assertSame('completed', $row->status);
        $this->assertSame(0, $row->remaining_days);
        $this->assertSame(100.0, $row->progress_percentage);
        $this->assertSame(3, $row->completed_days, 'The real attendance count is still the real count.');

        // 4 — and the money is a separate question.
        $this->assertSame(100.0, (float) $row->total_fee);
        $this->assertSame(0.0, $row->total_paid);
        $this->assertSame(100.0, $row->balance, 'A completed student may still owe the whole fee.');
        $this->assertSame(0, StudentPayment::count());
    }

    /* ================================================================
     | 9 — reports
     | ================================================================ */

    public function test_a_completed_student_remains_in_the_reports(): void
    {
        foreach (['students', 'student-progress'] as $report) {
            $rows = collect(app(ReportService::class)->build($report, [], $this->admin)['rows']);

            $this->assertContains(
                'Sihaam Cali Mumin',
                $rows->pluck(__('Student'))->all(),
                "The {$report} report should still list a completed student.",
            );
        }

        // Filterable by status, in both.
        $filtered = collect(app(ReportService::class)
            ->build('student-progress', ['status' => 'completed'], $this->admin)['rows']);

        $this->assertSame(['Sihaam Cali Mumin'], $filtered->pluck(__('Student'))->all());
        $this->assertSame('0', (string) $filtered->first()[__('Remaining')]);
        $this->assertSame('100%', $filtered->first()[__('Progress')]);

        // Their attendance history is still there to report on.
        $this->assertSame(3, Attendance::where('student_id', $this->finished->id)->count());
    }

    /* ================================================================
     | 5 (brief) — instructor-facing behaviour
     | ================================================================ */

    /** Their instructor still sees them in the record list. */
    public function test_the_instructor_still_sees_their_completed_student(): void
    {
        $names = $this->actingAs($this->teacherUser)
            ->get(route('instructor.students.index'))
            ->assertOk()
            ->viewData('students')
            ->pluck('full_name')
            ->all();

        $this->assertContains('Sihaam Cali Mumin', $names);
        $this->assertContains('Ubax Abdi Xirsi', $names);

        // And can open them.
        $this->actingAs($this->teacherUser)
            ->get(route('instructor.students.show', $this->finished))
            ->assertOk()
            ->assertSee('Sihaam Cali Mumin');
    }

    /**
     * 10 — but the waiting queue is operational, not a record list. A completed
     * student is not an active student, so they are neither offered nor
     * accepted, and nothing puts them there by itself.
     */
    public function test_a_completed_student_is_never_added_to_the_waiting_queue(): void
    {
        $addable = app(TrainingQueueService::class)->addableFor($this->instructor->id);

        $this->assertNotContains('Sihaam Cali Mumin', $addable->pluck('full_name')->all());
        $this->assertContains('Ubax Abdi Xirsi', $addable->pluck('full_name')->all());

        $verdict = app(TrainingEligibilityService::class)
            ->canQueueStudent($this->instructor, $this->finished);

        $this->assertFalse($verdict->eligible);
        $this->assertSame(QueueEligibility::NOT_ACTIVE, $verdict->code);

        // Posting their id straight at the route is refused too.
        $this->actingAs($this->teacherUser)
            ->post(route('instructor.training.queue.store'), [
                'student_id' => $this->finished->id,
                'assigned_duration_minutes' => 30,
            ])->assertInvalid(['student_id']);

        // And opening the console enrols nobody, completed or otherwise.
        $this->actingAs($this->teacherUser)->get(route('instructor.training.index'))->assertOk();
        $this->actingAs($this->teacherUser)->get(route('instructor.training.board'))->assertOk();

        $this->assertSame(0, TrainingQueueEntry::count());
    }

    /* ================================================================
     | Nothing is hidden, archived or destroyed
     | ================================================================ */

    public function test_completed_students_and_their_history_stay_intact(): void
    {
        $before = $this->finished->getAttributes();

        // Read every screen that touches them.
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.students.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.students.index', ['status' => 'completed']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.students.show', $this->finished))->assertOk();
        $this->actingAs($this->teacherUser)->get(route('instructor.students.index'))->assertOk();
        app(ReportService::class)->build('students', [], $this->admin);

        $this->assertSame($before, $this->finished->fresh()->getAttributes(), 'Reading changes nothing.');
        $this->assertSame(3, Attendance::count(), 'No attendance was created or removed.');
        $this->assertSame(0, StudentPayment::count());
        $this->assertNull($this->finished->fresh()->deleted_at, 'Nobody was archived out of sight.');
        $this->assertSame(2, Student::count());
    }
}
