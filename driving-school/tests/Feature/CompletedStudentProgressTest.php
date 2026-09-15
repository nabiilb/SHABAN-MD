<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\ReportService;
use App\Services\StudentProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A student the school has finished with has nothing left to train.
 *
 * The Students list showed rows reading "Completed" with 30 days remaining and
 * 0% progress, because Remaining and Progress were counted from the attendance
 * ledger alone and the status was never consulted. For the register import that
 * is every completed student it brought in: the school finished with them
 * before this system existed, so there is no attendance to count, and the list
 * reported people the school considers done as not having started.
 *
 * The status is the school's own statement about the student, so it wins.
 * Nothing is written to make it true — no attendance is invented, no day count
 * is faked — and changing the status back reveals the real figures again,
 * because the figures were never overwritten in the first place.
 */
class CompletedStudentProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Instructor $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->instructor = $this->makeInstructor('Cabdi Yuusuf');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Marks a student present on `$days` distinct dates. */
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

    /** What the Students list renders for one student. */
    private function rowFor(Student $student): Student
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.students.index'))
            ->viewData('students')
            ->firstWhere('id', $student->id);
    }

    /* ================================================================
     | 1 + 3 + 9 — the imported case from the screenshot
     | ================================================================ */

    /**
     * 1 + 3 — exactly the row in the screenshot: Completed, 30 remaining, 0%.
     * The register import carries no attendance, and it does not need to.
     */
    public function test_a_completed_student_with_no_attendance_reads_as_finished(): void
    {
        $student = $this->makeStudent('Sihaam Cali Mumin', $this->instructor, [
            'required_training_days' => 30,
        ]);
        $student->update(['status' => 'completed']);

        $this->assertSame(0, $student->completed_days, 'No attendance is invented.');
        $this->assertSame(0, $student->remaining_days);
        $this->assertSame(100.0, $student->progress_percentage);
        $this->assertTrue($student->hasCompletedTraining());

        // And nothing was written to make it so.
        $this->assertSame(0, Attendance::count());
        $this->assertSame(30, (int) $student->fresh()->required_training_days);
    }

    /** 2 — partial attendance is still finished, not 40% finished. */
    public function test_a_completed_student_with_partial_attendance_reads_as_finished(): void
    {
        $student = $this->makeStudent('Xamdi HASSAN ABDULLE', $this->instructor, [
            'required_training_days' => 30,
        ]);
        $this->attend($student, 12);
        $student->refresh()->update(['status' => 'completed']);

        $student->refresh();

        $this->assertSame(12, $student->completed_days, 'The real days attended are still the real days.');
        $this->assertSame(0, $student->remaining_days);
        $this->assertSame(100.0, $student->progress_percentage);
        $this->assertSame(12, Attendance::count(), 'The ledger is untouched.');
    }

    /** 3 — the same, through the list the screenshot was taken from. */
    public function test_the_students_list_shows_no_completed_student_with_days_remaining(): void
    {
        $finished = $this->makeStudent('Sumaya Ali Mumin', $this->instructor, ['required_training_days' => 30]);
        $finished->update(['status' => 'completed']);

        $partly = $this->makeStudent('Ubax Abdi Xirsi', $this->instructor, ['required_training_days' => 30]);
        $this->attend($partly, 6);

        $rows = $this->actingAs($this->admin)
            ->get(route('admin.students.index'))
            ->assertOk()
            ->viewData('students');

        foreach ($rows as $row) {
            if ($row->status === 'completed') {
                $this->assertSame(0, $row->remaining_days, "{$row->full_name} is completed but has days remaining.");
                $this->assertSame(100.0, $row->progress_percentage, "{$row->full_name} is completed but is not at 100%.");
            }
        }

        // The unfinished student is reported honestly alongside them.
        $this->assertSame(24, $rows->firstWhere('id', $partly->id)->remaining_days);
        $this->assertSame(20.0, $rows->firstWhere('id', $partly->id)->progress_percentage);
    }

    /* ================================================================
     | 4 + 5 + 6 — everybody else is unchanged
     | ================================================================ */

    /** 4 — an active student keeps the calculation they always had. */
    public function test_an_active_student_keeps_the_real_calculation(): void
    {
        $student = $this->makeStudent('Sumayo Shariif Maxamed', $this->instructor, [
            'required_training_days' => 30,
        ]);
        $this->attend($student, 12);
        $student->refresh();

        $this->assertSame(12, $student->completed_days);
        $this->assertSame(18, $student->remaining_days);
        $this->assertSame(40.0, $student->progress_percentage);
    }

    /** Suspended and cancelled students are not completed, so nothing changes. */
    public function test_suspended_and_cancelled_students_keep_the_real_calculation(): void
    {
        foreach (['suspended', 'cancelled'] as $status) {
            $student = $this->makeStudent("Student {$status}", $this->instructor, [
                'required_training_days' => 20,
            ]);
            $this->attend($student, 5);
            $student->refresh()->update(['status' => $status]);

            $student->refresh();

            $this->assertSame(15, $student->remaining_days, "A {$status} student keeps their real remaining days.");
            $this->assertSame(25.0, $student->progress_percentage);
        }
    }

    /** 5 — progress is clamped to 0–100 even when attendance overruns. */
    public function test_progress_is_clamped_between_zero_and_one_hundred(): void
    {
        $student = $this->makeStudent('Over Attender', $this->instructor, ['required_training_days' => 5]);
        $this->attend($student, 9);

        // Kept active deliberately: the clamp, not the completed rule, is what
        // is being tested here.
        $student->refresh()->forceFill(['status' => 'active'])->save();
        $student->refresh();

        $this->assertSame(9, $student->completed_days);
        $this->assertSame(100.0, $student->progress_percentage, 'Nine days of five is still 100%, not 180%.');

        // And a student with no course length at all reports 0, not a division
        // by zero.
        $noCourse = $this->makeStudent('No Course', $this->instructor, ['required_training_days' => 1]);
        $noCourse->forceFill(['required_training_days' => 0])->save();

        $this->assertSame(0.0, $noCourse->fresh()->progress_percentage);
        $this->assertSame(0, $noCourse->fresh()->remaining_days);
    }

    /** 6 — remaining days never go below zero. */
    public function test_remaining_days_never_go_below_zero(): void
    {
        $student = $this->makeStudent('Over Attender Two', $this->instructor, ['required_training_days' => 5]);
        $this->attend($student, 9);
        $student->refresh()->forceFill(['status' => 'active'])->save();

        $this->assertSame(0, $student->fresh()->remaining_days);
    }

    /* ================================================================
     | 7 + 8 — changing the status, both ways
     | ================================================================ */

    /** 7 — marking a student complete by hand takes effect at once. */
    public function test_marking_a_student_completed_updates_the_display_immediately(): void
    {
        $student = $this->makeStudent('Yaasiin Cabdinasir Xaaji', $this->instructor, [
            'required_training_days' => 30,
        ]);
        $this->attend($student, 12);
        $student->refresh();

        $this->assertSame(18, $student->remaining_days);
        $this->assertSame(40.0, $student->progress_percentage);

        $this->actingAs($this->admin)->put(route('admin.students.update', $student), [
            'full_name' => $student->full_name,
            'phone' => $student->phone,
            'start_date' => $student->start_date->toDateString(),
            'required_training_days' => 30,
            'current_instructor_id' => $this->instructor->id,
            'status' => 'completed',
            'total_fee' => (float) $student->total_fee,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $student->refresh();

        $this->assertSame('completed', $student->status);
        $this->assertSame(0, $student->remaining_days);
        $this->assertSame(100.0, $student->progress_percentage);
        $this->assertSame(12, Attendance::count(), 'No attendance was fabricated to reach 100%.');
        $this->assertSame(12, $student->completed_days);
    }

    /**
     * 8 — the brief's own example. Reopening a student shows the real figures
     * again, because the real figures were never overwritten.
     */
    public function test_reopening_a_completed_student_restores_the_real_calculation(): void
    {
        $student = $this->makeStudent('Reopened', $this->instructor, ['required_training_days' => 30]);
        $this->attend($student, 12);
        $student->refresh()->update(['status' => 'completed']);

        $this->assertSame(0, $student->fresh()->remaining_days);
        $this->assertSame(100.0, $student->fresh()->progress_percentage);

        $student->fresh()->update(['status' => 'active']);
        $student->refresh();

        $this->assertSame(12, $student->completed_days, 'The real day count was never replaced with 30.');
        $this->assertSame(18, $student->remaining_days);
        $this->assertSame(40.0, $student->progress_percentage);
        $this->assertSame(12, Attendance::count());
    }

    /* ================================================================
     | 9 — money is a different question entirely
     | ================================================================ */

    /**
     * Finishing training says nothing about having paid for it. "Remaining"
     * here is training days; the financial balance is its own figure, derived
     * from payments, and this rule does not go near it.
     */
    public function test_completing_training_does_not_touch_the_financial_balance(): void
    {
        $student = $this->makeStudent('Owes Money', $this->instructor, ['required_training_days' => 30]);
        $student->update(['total_fee' => 100]);

        StudentPayment::create([
            'payment_number' => 'PAY-0001',
            'student_id' => $student->id,
            'amount' => 40,
            'payment_date' => today(),
            'payment_method' => 'cash',
            'created_by' => $this->admin->id,
        ]);

        $student->refresh()->update(['status' => 'completed']);
        $student->refresh();

        // Training: finished.
        $this->assertSame(0, $student->remaining_days);
        $this->assertSame(100.0, $student->progress_percentage);

        // Money: still owed, to the penny.
        $this->assertSame(100.0, (float) $student->total_fee);
        $this->assertSame(40.0, $student->total_paid);
        $this->assertSame(60.0, $student->balance);
        $this->assertSame(1, StudentPayment::count(), 'No payment was created or removed.');
        $this->assertSame(
            60.0,
            app(DashboardService::class)->outstandingFees(),
            'Finishing training does not settle the bill.',
        );
    }

    /** A cancelled student drops out of what is owed; a completed one does not. */
    public function test_a_completed_student_still_owes_what_they_owe(): void
    {
        $student = $this->makeStudent('Still Owing', $this->instructor, ['required_training_days' => 30]);
        $student->update(['total_fee' => 100, 'status' => 'completed']);

        $this->assertSame(100.0, app(DashboardService::class)->adminMetrics()['outstanding_fees']);
        $this->assertSame(0.0, app(DashboardService::class)->adminMetrics()['total_income']);
    }

    /* ================================================================
     | 10 — every screen agrees
     | ================================================================ */

    public function test_every_screen_reports_the_same_figures(): void
    {
        $student = $this->makeStudent('Yahye', $this->instructor, ['required_training_days' => 30]);
        $this->attend($student, 2);
        $student->refresh()->update(['status' => 'completed']);
        $student->refresh();

        $expected = ['remaining' => 0, 'progress' => 100.0];

        // Students list.
        $row = $this->rowFor($student);
        $this->assertSame($expected['remaining'], $row->remaining_days);
        $this->assertSame($expected['progress'], $row->progress_percentage);

        // Student details.
        $detail = $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->viewData('student');
        $this->assertSame($expected['remaining'], $detail->remaining_days);
        $this->assertSame($expected['progress'], $detail->progress_percentage);

        // The instructor's own student list.
        $instructorRow = $this->actingAs($this->instructor->user)
            ->get(route('instructor.students.index'))
            ->assertOk()
            ->viewData('students')
            ->firstWhere('id', $student->id);
        $this->assertSame($expected['remaining'], $instructorRow->remaining_days);
        $this->assertSame($expected['progress'], $instructorRow->progress_percentage);

        // The instructor's view of that student.
        $instructorDetail = $this->actingAs($this->instructor->user)
            ->get(route('instructor.students.show', $student))
            ->assertOk()
            ->viewData('student');
        $this->assertSame($expected['remaining'], $instructorDetail->remaining_days);
        $this->assertSame($expected['progress'], $instructorDetail->progress_percentage);

        // The progress service's summary.
        $summary = app(StudentProgressService::class)->summarise($student);
        $this->assertSame($expected['remaining'], $summary['remaining_days']);
        $this->assertSame($expected['progress'], $summary['progress']);

        // Both reports that carry the columns.
        foreach (['students', 'student-progress'] as $report) {
            $rows = collect(app(ReportService::class)->build($report, [], $this->admin)['rows']);
            $reported = $rows->first(fn ($r) => ($r[__('Student')] ?? $r[__('Name')] ?? null) === 'Yahye');

            $this->assertNotNull($reported, "The {$report} report should list the student.");
            $this->assertSame('0', (string) $reported[__('Remaining')]);
            $this->assertSame('100%', $reported[__('Progress')]);
        }
    }

    /** The student's own pages agree too. */
    public function test_the_students_own_pages_agree(): void
    {
        $student = $this->makeStudent('Self Service', $this->instructor, ['required_training_days' => 30]);
        $this->attend($student, 3);

        $user = $this->makeUser(Role::STUDENT, ['name' => 'Self Service']);
        $student->refresh()->update(['user_id' => $user->id, 'status' => 'completed']);

        $metrics = app(DashboardService::class)->studentMetrics($student->fresh());

        $this->assertSame(0, $metrics['remaining_days']);
        $this->assertSame(100.0, $metrics['progress']);
        $this->assertSame(3, $metrics['completed_days'], 'The real attendance count is still reported as itself.');

        $this->actingAs($user)->get(route('student.progress'))->assertOk()->assertSee('100');
    }
}
