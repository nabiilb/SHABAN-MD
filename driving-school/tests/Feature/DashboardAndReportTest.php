<?php

namespace Tests\Feature;

use App\Models\CompanyExpense;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\StudentPayment;
use App\Models\Supplier;
use App\Services\DashboardService;
use App\Services\DebtService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardAndReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_admin_dashboard_figures_are_computed_from_the_database(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $instructor = $this->makeInstructor('Xasan Maxamuud');
        $student = $this->makeStudent('Ilyas', $instructor);

        StudentPayment::create([
            'payment_number' => 'PAY-0001', 'student_id' => $student->id, 'amount' => 300,
            'payment_date' => Carbon::today(), 'payment_method' => 'cash',
        ]);

        CompanyExpense::create([
            'expense_number' => 'EXP-0001',
            'expense_category_id' => ExpenseCategory::where('code', 'rent')->value('id'),
            'description' => 'Office rent', 'amount' => 120,
            'expense_date' => Carbon::today(), 'payment_method' => 'cash',
        ]);

        $supplier = Supplier::create([
            'supplier_number' => 'SUP-0001', 'name' => 'Bakaaro Garage',
            'supplier_type' => 'garage', 'status' => 'active',
        ]);

        app(DebtService::class)->createDebt([
            'supplier_id' => $supplier->id,
            'description' => 'Garage service',
            'original_amount' => 500,
            'debt_date' => Carbon::today(),
        ], $admin);

        $metrics = app(DashboardService::class)->adminMetrics();

        $this->assertSame(300.0, $metrics['total_income']);
        // The unpaid $500 debt is NOT an expense.
        $this->assertSame(120.0, $metrics['total_expenses']);
        $this->assertSame(180.0, $metrics['net_profit']);
        $this->assertSame(500.0, $metrics['outstanding_debt']);
        $this->assertSame(1, $metrics['active_students']);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Ilyas');
    }

    public function test_instructor_metrics_never_include_another_instructors_work(): void
    {
        $xasanUser = $this->makeUser(Role::INSTRUCTOR);
        $xasan = $this->makeInstructor('Xasan Maxamuud', $xasanUser);
        $nasteexo = $this->makeInstructor('Nasteexo Aadan');

        $this->makeStudent('Ilyas', $xasan);
        $this->makeStudent('Maryan', $xasan);
        $this->makeStudent('Ahmed', $nasteexo);
        $this->makeStudent('Fatima', $nasteexo);

        $this->makeVehicle('AD-01', $xasan);
        $this->makeVehicle('AD-02', $nasteexo);

        $metrics = app(DashboardService::class)->instructorMetrics($xasan);

        $this->assertSame(2, $metrics['my_students']);
        $this->assertSame(1, $metrics['my_vehicles']);
        $this->assertSame(0.0, $metrics['outstanding_loan']);
        $this->assertArrayNotHasKey('total_income', $metrics);
        $this->assertArrayNotHasKey('net_profit', $metrics);
        $this->assertArrayNotHasKey('outstanding_debt', $metrics);
    }

    public function test_an_admin_can_open_every_report_and_export_it(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $instructor = $this->makeInstructor('Xasan Maxamuud');
        $this->makeStudent('Ilyas', $instructor);

        foreach (ReportService::ADMIN_REPORTS as $report) {
            $this->actingAs($admin)->get(route('admin.reports.show', $report))->assertOk();
        }

        $this->actingAs($admin)
            ->get(route('admin.reports.show', ['report' => 'students', 'export' => 'csv']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_an_unknown_report_returns_404(): void
    {
        $admin = $this->makeUser(Role::ADMIN);

        $this->actingAs($admin)->get(route('admin.reports.show', 'made-up-report'))->assertNotFound();
    }

    public function test_an_instructor_can_only_run_the_four_my_reports(): void
    {
        $user = $this->makeUser(Role::INSTRUCTOR);
        $xasan = $this->makeInstructor('Xasan Maxamuud', $user);
        $nasteexo = $this->makeInstructor('Nasteexo Aadan');

        $this->makeStudent('Ilyas', $xasan);
        $this->makeStudent('Ahmed', $nasteexo);

        foreach (ReportService::INSTRUCTOR_REPORTS as $report) {
            $this->actingAs($user)->get(route('instructor.reports.show', $report))->assertOk();
        }

        // Company reports are not reachable from the instructor namespace...
        $this->actingAs($user)->get(route('instructor.reports.show', 'expenses'))->assertNotFound();
        $this->actingAs($user)->get(route('instructor.reports.show', 'debts'))->assertNotFound();

        // ...nor from the admin one.
        $this->actingAs($user)->get(route('admin.reports.show', 'expenses'))->assertForbidden();
    }

    public function test_an_instructor_report_contains_only_his_own_students(): void
    {
        $user = $this->makeUser(Role::INSTRUCTOR);
        $xasan = $this->makeInstructor('Xasan Maxamuud', $user);
        $nasteexo = $this->makeInstructor('Nasteexo Aadan');

        $this->makeStudent('Ilyas', $xasan);
        $this->makeStudent('Ahmed', $nasteexo);

        $this->actingAs($user)
            ->get(route('instructor.reports.show', 'my-students'))
            ->assertOk()
            ->assertSee('Ilyas')
            ->assertDontSee('Ahmed');
    }

    public function test_a_filter_cannot_widen_an_instructors_report_to_another_instructor(): void
    {
        $user = $this->makeUser(Role::INSTRUCTOR);
        $xasan = $this->makeInstructor('Xasan Maxamuud', $user);
        $nasteexo = $this->makeInstructor('Nasteexo Aadan');

        $this->makeStudent('Ilyas', $xasan);
        $ahmed = $this->makeStudent('Ahmed', $nasteexo);

        // Hand-crafting the filter with someone else's student id changes nothing.
        $this->actingAs($user)
            ->get(route('instructor.reports.show', ['report' => 'my-attendance', 'student_id' => $ahmed->id]))
            ->assertOk()
            ->assertDontSee('Ahmed');
    }
}
