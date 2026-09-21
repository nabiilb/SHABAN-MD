<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Two figures a manager reads off a screen and has to be able to trust.
 *
 * The Unpaid Student Fees card and the page behind it are the same query asked
 * twice — summed on the dashboard, listed on the page — so the total somebody
 * clicks and the rows they land on cannot disagree.
 *
 * And the Training Console's search says how many DAYS a student has left. It
 * used to print the day count and the progress percentage as two bare numbers
 * side by side, so "Remaining: 30" next to "0%" read as "Remaining: 300%", and
 * "Remaining: 1" next to "46.7%" read as "Remaining: 146.7%".
 */
class UnpaidFeesAndRemainingDaysTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacherUser;

    private Instructor $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-19 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->teacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Cabdi']);
        $this->instructor = $this->makeInstructor('Cabdi', $this->teacherUser);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function student(string $name, float $fee, float $paid = 0, string $status = 'active'): Student
    {
        $student = $this->makeStudent($name, $this->instructor);
        $student->forceFill(['total_fee' => $fee, 'status' => $status])->save();

        if ($paid > 0) {
            StudentPayment::create([
                'payment_number' => 'PAY-'.str_pad((string) (StudentPayment::count() + 1), 4, '0', STR_PAD_LEFT),
                'student_id' => $student->id,
                'amount' => $paid,
                'payment_date' => today(),
                'payment_method' => 'cash',
                'created_by' => $this->admin->id,
            ]);
        }

        return $student->refresh();
    }

    /* ================================================================
     | Unpaid student fees
     | ================================================================ */

    public function test_the_dashboard_card_opens_the_unpaid_students_page(): void
    {
        $this->student('Owes Everything', 100);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Unpaid Student Fees')
            ->assertSee(route('admin.students.unpaid'), false);

        $this->actingAs($this->admin)
            ->get(route('admin.students.unpaid'))
            ->assertOk()
            ->assertSee('Owes Everything');
    }

    /** The page's total is the dashboard's total, to the penny. */
    public function test_the_page_total_matches_the_dashboard_total(): void
    {
        $this->student('Owes All', 100);
        $this->student('Part Paid', 200, 50);
        $this->student('Paid Up', 80, 80);
        $this->student('Overpaid', 50, 200);
        $this->student('Left The School', 300, 0, 'cancelled');

        $dashboard = app(DashboardService::class)->adminMetrics()['outstanding_fees'];
        $page = $this->actingAs($this->admin)->get(route('admin.students.unpaid'))->assertOk();

        // 100 owed + 150 owed. The overpayment offsets nobody, the fully paid
        // student owes nothing, and a cancelled student has left.
        $this->assertSame(250.0, $dashboard);
        $this->assertSame($dashboard, $page->viewData('outstanding'));
        $this->assertSame($dashboard, $page->viewData('filtered'));

        $rows = $page->viewData('students');
        $this->assertSame(250.0, round((float) $rows->sum('remaining_amount'), 2));
    }

    /** Fully paid, overpaid and cancelled students are not rows at all. */
    public function test_only_students_who_owe_are_listed(): void
    {
        $this->student('Owes All', 100);
        $this->student('Paid Up', 80, 80);
        $this->student('Overpaid', 50, 200);
        $this->student('Left The School', 300, 0, 'cancelled');

        $names = $this->actingAs($this->admin)
            ->get(route('admin.students.unpaid'))
            ->viewData('students')
            ->pluck('full_name')
            ->all();

        $this->assertSame(['Owes All'], $names);
    }

    /** Each row carries the figures the page promises, from the canonical query. */
    public function test_each_row_carries_fee_paid_and_remaining(): void
    {
        $student = $this->student('Part Paid', 200, 50);

        $row = $this->actingAs($this->admin)
            ->get(route('admin.students.unpaid'))
            ->viewData('students')
            ->firstWhere('id', $student->id);

        $this->assertSame(200.0, (float) $row->total_fee);
        $this->assertSame(50.0, (float) $row->paid);
        $this->assertSame(150.0, (float) $row->remaining_amount);
        $this->assertSame('active', $row->status);
        $this->assertSame($student->phone, $row->phone);

        // And it agrees with the student's own canonical balance.
        $this->assertSame(150.0, $student->fresh()->balance);
    }

    /** The page links through to the student and to recording a payment. */
    public function test_the_page_links_to_the_student_and_their_payments(): void
    {
        $student = $this->student('Owes All', 100);

        $this->actingAs($this->admin)
            ->get(route('admin.students.unpaid'))
            ->assertOk()
            ->assertSee(route('admin.students.show', $student), false)
            ->assertSee(route('admin.student-payments.create', ['student_id' => $student->id]), false);
    }

    /** A new payment moves both figures together, because they are one query. */
    public function test_recording_a_payment_moves_the_card_and_the_page_together(): void
    {
        $student = $this->student('Part Paid', 200, 50);

        $this->actingAs($this->admin)->post(route('admin.student-payments.store'), [
            'student_id' => $student->id,
            'amount' => 150,
            'payment_date' => today()->toDateString(),
            'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame(0.0, app(DashboardService::class)->adminMetrics()['outstanding_fees']);
        $this->assertSame(
            [],
            $this->actingAs($this->admin)->get(route('admin.students.unpaid'))->viewData('students')->pluck('full_name')->all(),
        );
    }

    /** An instructor has no business on the admin's arrears page. */
    public function test_the_page_is_admin_only(): void
    {
        $this->actingAs($this->teacherUser)->get(route('admin.students.unpaid'))->assertForbidden();
    }

    /* ================================================================
     | Training Console: days, not percentages
     | ================================================================ */

    public function test_the_console_search_reports_remaining_days_not_a_percentage(): void
    {
        $student = $this->makeStudent('Ahmed Ali', $this->instructor, ['required_training_days' => 30]);

        $row = $this->actingAs($this->teacherUser)
            ->getJson(route('instructor.training.students.search', ['q' => 'Ahmed']))
            ->assertOk()
            ->json('results.0');

        // The number is a day count, and it carries its unit.
        $this->assertSame(30, $row['remaining_days']);
        $this->assertSame('30 days', $row['remaining_label']);
        $this->assertIsInt($row['remaining_days']);

        // Progress is its own field, and never appears in the remaining one.
        $this->assertSame(0.0, (float) $row['progress']);
        $this->assertStringNotContainsString('%', $row['remaining_label']);
    }

    /** One day is a day, not days — and none is none. */
    public function test_the_day_count_reads_naturally(): void
    {
        $cases = [
            ['required' => 30, 'attended' => 29, 'label' => '1 day'],
            ['required' => 30, 'attended' => 28, 'label' => '2 days'],
            ['required' => 30, 'attended' => 30, 'label' => '0 days'],
        ];

        foreach ($cases as $index => $case) {
            $student = $this->makeStudent("Student {$index}", $this->instructor, [
                'required_training_days' => $case['required'],
            ]);

            foreach (range(1, $case['attended']) as $offset) {
                Attendance::create([
                    'student_id' => $student->id,
                    'instructor_id' => $this->instructor->id,
                    'attendance_date' => today()->copy()->subDays($offset)->toDateString(),
                    'status' => 'present',
                    'recorded_by' => $this->admin->id,
                ]);
            }

            $row = $this->actingAs($this->teacherUser)
                ->getJson(route('instructor.training.students.search', ['q' => "Student {$index}"]))
                ->assertOk()
                ->json('results.0');

            $this->assertSame($case['label'], $row['remaining_label'], "Failed for {$case['attended']} of {$case['required']}.");
        }
    }

    /** An imported student reports the register's own remaining days. */
    public function test_an_imported_students_remaining_days_come_from_the_opening_balance(): void
    {
        $student = $this->makeStudent('Imported One', $this->instructor, ['required_training_days' => 30]);
        $student->forceFill([
            'opening_remaining_days' => 15,
            'opening_remaining_from' => today(),
        ])->save();

        $row = $this->actingAs($this->teacherUser)
            ->getJson(route('instructor.training.students.search', ['q' => 'Imported']))
            ->assertOk()
            ->json('results.0');

        $this->assertSame(15, $row['remaining_days']);
        $this->assertSame('15 days', $row['remaining_label']);
        $this->assertSame(50.0, (float) $row['progress']);
    }

    /** The two figures are rendered with their own labels, so neither can be read as the other. */
    public function test_the_console_labels_both_figures(): void
    {
        $html = $this->actingAs($this->teacherUser)
            ->get(route('instructor.training.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('student.remaining_label', $html);
        $this->assertStringContainsString('Progress: ${student.progress}%', $html);
        $this->assertStringNotContainsString('Remaining: ${student.remaining_days}', $html);
    }
}
