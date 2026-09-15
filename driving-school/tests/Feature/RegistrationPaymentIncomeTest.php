<?php

namespace Tests\Feature;

use App\Models\CompanyExpense;
use App\Models\ExpenseCategory;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\StudentPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Money taken at the counter when a student registers.
 *
 * The thing to understand before reading any of this: a student payment IS the
 * company's income entry. There is no second income table to write to and no
 * mirror row to keep in step — Income & Expenses, the dashboard and the reports
 * all read company income straight out of `student_payments`. So "one payment,
 * exactly one income entry" is not a rule the application has to enforce; it is
 * the shape of the data. These tests hold it to that, from both ends: the money
 * must appear in the accounts, and it must appear exactly once.
 */
class RegistrationPaymentIncomeTest extends TestCase
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
        $this->instructor = $this->makeInstructor('Xasan');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Registers a student through the real form POST. */
    private function register(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.students.store'), [
            'full_name' => 'Abdirizak HASSAN',
            'phone' => '+252611000111',
            'start_date' => today()->toDateString(),
            'required_training_days' => 24,
            'current_instructor_id' => $this->instructor->id,
            'status' => 'active',
            'total_fee' => 100,
            ...$overrides,
        ]);
    }

    /** Company income exactly as the Income & Expenses page computes it. */
    private function incomeOnThePage(): float
    {
        return (float) $this->actingAs($this->admin)
            ->get(route('admin.expenses.index'))
            ->viewData('totals')['income'];
    }

    /* ================================================================
     | 1 — fee 100, paid 40
     | ================================================================ */

    public function test_registering_with_a_partial_payment_records_the_payment_and_the_income(): void
    {
        $this->register(['amount_paid' => 40])->assertRedirect();

        $student = Student::firstOrFail();

        // The payment — the authoritative record of the money.
        $this->assertSame(1, StudentPayment::count());
        $payment = StudentPayment::firstOrFail();
        $this->assertSame($student->id, $payment->student_id);
        $this->assertSame('40.00', $payment->amount);
        $this->assertSame(today()->toDateString(), $payment->payment_date->toDateString());
        $this->assertSame('cash', $payment->payment_method);
        $this->assertSame($this->admin->id, $payment->created_by);
        $this->assertStringContainsString('Initial registration payment', (string) $payment->notes);
        $this->assertStringStartsWith('PAY-', $payment->payment_number);

        // The income — which is that same row, counted once.
        $this->assertSame(40.0, $this->incomeOnThePage());
        $this->assertSame(40.0, app(DashboardService::class)->adminMetrics()['total_income']);

        // The balance.
        $this->assertSame(100.0, (float) $student->total_fee);
        $this->assertSame(40.0, $student->total_paid);
        $this->assertSame(60.0, $student->balance);
        $this->assertSame(60.0, app(DashboardService::class)->adminMetrics()['outstanding_fees']);
    }

    public function test_the_registration_records_the_chosen_method_and_reference(): void
    {
        $this->register([
            'amount_paid' => 40,
            'payment_method' => 'mobile_money',
            'payment_reference' => 'EVC-99812',
        ])->assertRedirect();

        $payment = StudentPayment::firstOrFail();

        $this->assertSame('mobile_money', $payment->payment_method);
        $this->assertSame('EVC-99812', $payment->reference);
    }

    /* ================================================================
     | 2 — paid 0
     | ================================================================ */

    public function test_registering_with_nothing_paid_records_no_payment_and_no_income(): void
    {
        $this->register(['amount_paid' => 0])->assertRedirect();

        $student = Student::firstOrFail();

        $this->assertSame(0, StudentPayment::count());
        $this->assertSame(0.0, $this->incomeOnThePage());
        $this->assertSame(0.0, app(DashboardService::class)->adminMetrics()['total_income']);
        $this->assertSame(100.0, $student->balance, 'The whole fee is still owed.');
    }

    /** An omitted Amount Paid behaves exactly like a zero. */
    public function test_registering_without_an_amount_paid_field_records_nothing(): void
    {
        $this->register()->assertRedirect();

        $this->assertSame(1, Student::count());
        $this->assertSame(0, StudentPayment::count());
        $this->assertSame(100.0, Student::firstOrFail()->balance);
    }

    /* ================================================================
     | 3 — paid in full
     | ================================================================ */

    public function test_registering_with_the_fee_paid_in_full_clears_the_balance(): void
    {
        $this->register(['amount_paid' => 100])->assertRedirect();

        $this->assertSame(1, StudentPayment::count());
        $this->assertSame('100.00', StudentPayment::firstOrFail()->amount);
        $this->assertSame(100.0, $this->incomeOnThePage());
        $this->assertSame(0.0, Student::firstOrFail()->balance);
        $this->assertSame(0.0, app(DashboardService::class)->adminMetrics()['outstanding_fees']);
    }

    /* ================================================================
     | 4 + 5 — one transaction, or nothing at all
     | ================================================================ */

    /**
     * 4 — the payment fails, so the student is not created either.
     *
     * The failure is injected where it would really happen: writing the payment
     * row. Nothing is left behind — no student, no assignment, no income.
     */
    public function test_a_failure_recording_the_payment_rolls_the_student_back(): void
    {
        // Let the exception out of the request rather than being rendered as a
        // 500 — the point is what survives in the database, and the handler
        // would hide the cause.
        $this->withoutExceptionHandling();

        $this->app->bind(StudentPaymentService::class, fn () => new class extends StudentPaymentService
        {
            public function recordRegistrationPayment(Student $student, array $data, User $actor): ?StudentPayment
            {
                throw new RuntimeException('Payment gateway exploded');
            }
        });

        try {
            $this->register(['amount_paid' => 40]);
            $this->fail('The registration should have failed with the payment.');
        } catch (RuntimeException $e) {
            $this->assertSame('Payment gateway exploded', $e->getMessage());
        }

        $this->assertSame(0, Student::count(), 'No student may survive a failed payment.');
        $this->assertSame(0, StudentPayment::count());
        $this->assertDatabaseCount('student_instructor_assignments', 0);
        $this->assertSame(0.0, app(DashboardService::class)->adminMetrics()['total_income']);
    }

    /**
     * 5 — the accounting write fails, so the student and the payment go too.
     *
     * Because the payment *is* the accounting entry, "income creation fails"
     * means the same row failing to commit. This proves the whole registration
     * is one unit either way: a rollback after the payment was written inside
     * the transaction leaves neither the student nor the money behind.
     */
    public function test_a_failure_after_the_payment_rolls_back_the_student_and_the_payment(): void
    {
        $registered = null;

        try {
            DB::transaction(function () use (&$registered) {
                $student = Student::create([
                    'student_number' => 'STD-9001',
                    'full_name' => 'Rolled Back',
                    'phone' => '+252611000222',
                    'start_date' => today(),
                    'required_training_days' => 24,
                    'status' => 'active',
                    'total_fee' => 100,
                ]);

                $registered = app(StudentPaymentService::class)
                    ->recordRegistrationPayment($student, ['amount' => 40], $this->admin);

                // The payment exists inside the transaction…
                $this->assertNotNull($registered);
                $this->assertSame(1, StudentPayment::count());

                throw new RuntimeException('Accounting write failed');
            });
            $this->fail('The transaction should have been rolled back.');
        } catch (RuntimeException $e) {
            $this->assertSame('Accounting write failed', $e->getMessage());
        }

        // …and nothing does outside it.
        $this->assertSame(0, Student::count());
        $this->assertSame(0, StudentPayment::count());
        $this->assertSame(0.0, $this->incomeOnThePage());
    }

    /* ================================================================
     | 6 + 7 — editing, and later payments
     | ================================================================ */

    /** 6 — editing a student never banks money. */
    public function test_editing_a_student_creates_no_further_income(): void
    {
        $this->register(['amount_paid' => 40])->assertRedirect();
        $student = Student::firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.students.update', $student), [
            'full_name' => 'Abdirizak HASSAN CILMI',
            'phone' => '+252611000999',
            'address' => 'Hodan, Muqdisho',
            'start_date' => $student->start_date->toDateString(),
            'required_training_days' => 24,
            'current_instructor_id' => $this->instructor->id,
            'status' => 'active',
            // The fee goes up; the money already taken does not change.
            'total_fee' => 120,
            // Posted deliberately: an edit must ignore it outright.
            'amount_paid' => 75,
            'payment_method' => 'cash',
        ])->assertRedirect();

        $student->refresh();

        $this->assertSame(1, StudentPayment::count(), 'Editing must not record a payment.');
        $this->assertSame(40.0, $student->total_paid);
        $this->assertSame(40.0, $this->incomeOnThePage());
        $this->assertSame(120.0, (float) $student->total_fee);
        $this->assertSame(80.0, $student->balance, 'Raising the fee only moves what is owed.');
    }

    /** 7 — a later payment adds exactly one more income entry. */
    public function test_a_later_payment_adds_exactly_one_more_income_entry(): void
    {
        $this->register(['amount_paid' => 40])->assertRedirect();
        $student = Student::firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.student-payments.store'), [
            'student_id' => $student->id,
            'amount' => 25,
            'payment_date' => today()->toDateString(),
            'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame(2, StudentPayment::count());
        $this->assertSame(65.0, $this->incomeOnThePage());
        $this->assertSame(65.0, $student->fresh()->total_paid);
        $this->assertSame(35.0, $student->fresh()->balance);

        // Both payments carry their own number; neither overwrote the other.
        $this->assertCount(2, StudentPayment::pluck('payment_number')->unique());
    }

    /* ================================================================
     | 8 — retrying cannot double the money
     | ================================================================ */

    public function test_retrying_the_registration_payment_does_not_duplicate_the_income(): void
    {
        $this->register(['amount_paid' => 40])->assertRedirect();
        $student = Student::firstOrFail();

        $service = app(StudentPaymentService::class);
        $first = StudentPayment::firstOrFail();

        foreach (range(1, 3) as $_) {
            $again = $service->recordRegistrationPayment($student, ['amount' => 40], $this->admin);
            $this->assertTrue($again->is($first), 'A retry returns the payment already recorded.');
        }

        $this->assertSame(1, StudentPayment::count());
        $this->assertSame(40.0, $this->incomeOnThePage());
    }

    /** Nothing paid stays nothing paid, however many times it is retried. */
    public function test_retrying_a_zero_registration_payment_stays_a_no_op(): void
    {
        $student = $this->makeStudent('Nothing Paid', $this->instructor);
        $service = app(StudentPaymentService::class);

        foreach (range(1, 3) as $_) {
            $this->assertNull($service->recordRegistrationPayment($student, ['amount' => 0], $this->admin));
        }

        $this->assertSame(0, StudentPayment::count());
    }

    /* ================================================================
     | 9 + 10 + 11 — history, reports, and where the balance comes from
     | ================================================================ */

    /** 9 — payments recorded before this change are left exactly as they were. */
    public function test_existing_payment_history_is_untouched(): void
    {
        $existing = $this->makeStudent('Imported Student', $this->instructor);
        $existing->update(['total_fee' => 500]);

        $historic = StudentPayment::create([
            'payment_number' => 'PAY-0001',
            'student_id' => $existing->id,
            'amount' => 150,
            'payment_date' => '2026-08-01',
            'payment_method' => 'cash',
            'created_by' => $this->admin->id,
        ]);
        $before = $historic->fresh()->getAttributes();

        $this->register(['amount_paid' => 40])->assertRedirect();

        $this->assertSame($before, $historic->fresh()->getAttributes());
        $this->assertSame(190.0, $this->incomeOnThePage(), 'The old payment still counts, alongside the new one.');
        $this->assertSame(150.0, $existing->fresh()->total_paid);
    }

    /** 10 — the finance page and the reports both show the registration money. */
    public function test_the_finance_page_and_reports_include_the_registration_payment(): void
    {
        $this->register(['amount_paid' => 40])->assertRedirect();

        CompanyExpense::create([
            'expense_number' => 'EXP-0001',
            'expense_category_id' => ExpenseCategory::value('id'),
            'description' => 'Diesel',
            'amount' => 15,
            'expense_date' => today(),
            'payment_method' => 'cash',
            'created_by' => $this->admin->id,
        ]);

        $totals = $this->actingAs($this->admin)
            ->get(route('admin.expenses.index'))
            ->assertOk()
            ->viewData('totals');

        $this->assertSame(40.0, $totals['income']);
        $this->assertSame(15.0, $totals['expenses']);
        $this->assertSame(25.0, $totals['net']);

        // The monthly income trend the dashboard chart reads.
        $trend = app(DashboardService::class)->incomeTrend(1);
        $this->assertSame(40.0, (float) $trend->last()['income']);
        $this->assertSame(15.0, (float) $trend->last()['expenses']);

        // And the Student Payments screen, which is the income ledger itself.
        $this->actingAs($this->admin)
            ->get(route('admin.student-payments.index'))
            ->assertOk()
            ->assertSee('Abdirizak HASSAN')
            ->assertSee('40.00');
    }

    /**
     * 11 — the balance is derived from payments, and only from payments.
     *
     * Proved by deleting the payment: income and balance both move, because
     * they are the same rows being counted. Nothing anywhere caches a total.
     */
    public function test_the_balance_is_derived_from_payments_not_from_a_stored_total(): void
    {
        $this->register(['amount_paid' => 40])->assertRedirect();
        $student = Student::firstOrFail();

        $this->assertSame(40.0, $student->total_paid);
        $this->assertSame(60.0, $student->balance);

        StudentPayment::firstOrFail()->delete();

        $this->assertSame(0.0, $student->fresh()->total_paid);
        $this->assertSame(100.0, $student->fresh()->balance);
        $this->assertSame(0.0, $this->incomeOnThePage(), 'A removed payment is removed income.');
        $this->assertSame(
            1,
            StudentPayment::withTrashed()->count(),
            'The row is soft-deleted, not destroyed — the history is still auditable.',
        );
    }

    /** The student's own page shows the money and what is left. */
    public function test_the_student_page_shows_the_payment_and_the_balance(): void
    {
        $this->register(['amount_paid' => 40])->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', Student::firstOrFail()))
            ->assertOk()
            ->assertSee('40.00')
            ->assertSee('60.00');
    }
}
