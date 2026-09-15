<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A student's Total Fee is what they owe, not what the school has earned.
 *
 * Income is the cash a student actually hands over — a student payment —
 * exactly as a company debt is not an expense until it is paid. Setting a fee
 * moves "Unpaid Student Fees"; paying it moves income.
 */
class StudentFeeIncomeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-14 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function metrics(): array
    {
        return app(DashboardService::class)->adminMetrics();
    }

    private function pay(Student $student, float $amount): StudentPayment
    {
        return StudentPayment::create([
            'payment_number' => 'PAY-'.str_pad((string) (StudentPayment::count() + 1), 4, '0', STR_PAD_LEFT),
            'student_id' => $student->id,
            'amount' => $amount,
            'payment_date' => today(),
            'payment_method' => 'cash',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_a_new_fee_is_owed_to_the_school_and_is_not_income_yet(): void
    {
        $student = $this->makeStudent('Abdirizak Hassan');
        $student->update(['total_fee' => 120]);

        $metrics = $this->metrics();

        $this->assertSame(0.0, $metrics['total_income']);
        $this->assertSame(120.0, $metrics['outstanding_fees']);
        $this->assertSame(120.0, $student->fresh()->balance);
    }

    public function test_paying_part_of_the_fee_moves_it_from_owed_to_income(): void
    {
        $student = $this->makeStudent('Abdirizak Hassan');
        $student->update(['total_fee' => 120]);

        $this->pay($student, 50);

        $metrics = $this->metrics();

        $this->assertSame(50.0, $metrics['total_income']);
        $this->assertSame(70.0, $metrics['outstanding_fees']);
        $this->assertSame(70.0, $student->fresh()->balance);

        // And paying the rest clears what is owed.
        $this->pay($student, 70);

        $this->assertSame(120.0, $this->metrics()['total_income']);
        $this->assertSame(0.0, $this->metrics()['outstanding_fees']);
    }

    public function test_fees_are_summed_across_students_without_a_query_each(): void
    {
        foreach ([100, 250, 75] as $index => $fee) {
            $student = $this->makeStudent('Student '.$index);
            $student->update(['total_fee' => $fee]);
        }

        $this->assertSame(425.0, $this->metrics()['outstanding_fees']);
    }

    /** An overpaid student must not cancel out somebody else's arrears. */
    public function test_an_overpayment_does_not_offset_another_students_balance(): void
    {
        $generous = $this->makeStudent('Generous');
        $generous->update(['total_fee' => 50]);
        $this->pay($generous, 200);

        $owing = $this->makeStudent('Owing');
        $owing->update(['total_fee' => 100]);

        $this->assertSame(100.0, $this->metrics()['outstanding_fees']);
        $this->assertSame(200.0, $this->metrics()['total_income']);
    }

    /** A cancelled student is not still owing the school money. */
    public function test_a_cancelled_student_drops_out_of_what_is_owed(): void
    {
        $student = $this->makeStudent('Left');
        $student->update(['total_fee' => 300]);

        $this->assertSame(300.0, $this->metrics()['outstanding_fees']);

        $student->update(['status' => 'cancelled']);

        $this->assertSame(0.0, $this->metrics()['outstanding_fees']);
    }

    public function test_the_dashboard_shows_the_figure_beside_income(): void
    {
        $student = $this->makeStudent('Abdirizak Hassan');
        $student->update(['total_fee' => 120]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Unpaid Student Fees')
            ->assertSee('120.00');
    }
}
