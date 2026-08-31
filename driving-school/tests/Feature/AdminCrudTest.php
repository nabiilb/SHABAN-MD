<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExpenseCategory;
use App\Models\Instructor;
use App\Models\InstructorLoan;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Proves the CRUD screens actually write to MySQL rather than being
 * decorative forms.
 */
class AdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->admin = $this->makeUser(Role::ADMIN);
    }

    public function test_an_admin_can_create_read_update_and_delete_a_student(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');

        $this->actingAs($this->admin)->post(route('admin.students.store'), [
            'full_name' => 'Ilyas Maxamed',
            'phone' => '+252612000001',
            'email' => 'ilyas@example.com',
            'start_date' => Carbon::today()->subDays(10)->toDateString(),
            'required_training_days' => 24,
            'current_instructor_id' => $instructor->id,
            'status' => 'active',
        ])->assertRedirect();

        $student = Student::firstWhere('full_name', 'Ilyas Maxamed');

        $this->assertNotNull($student);
        $this->assertSame('STD-0001', $student->student_number);
        // The initial assignment is opened automatically.
        $this->assertDatabaseHas('student_instructor_assignments', [
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'is_current' => true,
        ]);

        $this->actingAs($this->admin)->get(route('admin.students.show', $student))->assertOk()->assertSee('Ilyas Maxamed');

        $this->actingAs($this->admin)->put(route('admin.students.update', $student), [
            'full_name' => 'Ilyas Maxamed Cali',
            'phone' => '+252612000099',
            'start_date' => $student->start_date->toDateString(),
            'required_training_days' => 30,
            'current_instructor_id' => $instructor->id,
            'status' => 'active',
        ])->assertRedirect();

        $student->refresh();
        $this->assertSame('Ilyas Maxamed Cali', $student->full_name);
        $this->assertSame(30, $student->required_training_days);

        $this->actingAs($this->admin)->delete(route('admin.students.destroy', $student))->assertRedirect();
        $this->assertSoftDeleted('students', ['id' => $student->id]);
    }

    public function test_student_validation_rejects_missing_and_bad_input(): void
    {
        $this->actingAs($this->admin)->post(route('admin.students.store'), [
            'full_name' => '',
            'phone' => '',
            'email' => 'not-an-email',
            'start_date' => 'not-a-date',
            'required_training_days' => 0,
            'status' => 'imaginary',
        ])->assertSessionHasErrors(['full_name', 'phone', 'email', 'start_date', 'required_training_days', 'status']);

        $this->assertSame(0, Student::count());
    }

    public function test_an_admin_can_create_an_instructor_with_a_login_account(): void
    {
        $this->actingAs($this->admin)->post(route('admin.instructors.store'), [
            'full_name' => 'Nasteexo Aadan',
            'phone' => '+252611000102',
            'joining_date' => Carbon::today()->subYear()->toDateString(),
            'status' => 'active',
            'create_account' => 1,
            'account_email' => 'nasteexo@example.com',
            'account_password' => 'Instructor@12345',
        ])->assertRedirect();

        $instructor = Instructor::firstWhere('full_name', 'Nasteexo Aadan');

        $this->assertNotNull($instructor->user_id);
        $this->assertSame('nasteexo@example.com', $instructor->user->email);
        $this->assertTrue($instructor->user->isInstructor());
    }

    public function test_an_instructor_with_students_cannot_be_deleted(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');
        $this->makeStudent('Ilyas', $instructor);

        $this->actingAs($this->admin)
            ->delete(route('admin.instructors.destroy', $instructor))
            ->assertSessionHasErrors('instructor');

        $this->assertDatabaseHas('instructors', ['id' => $instructor->id, 'deleted_at' => null]);
    }

    public function test_an_admin_can_manage_vehicles(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');

        $this->actingAs($this->admin)->post(route('admin.vehicles.store'), [
            'plate_number' => 'AD-01',
            'make' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2018,
            'color' => 'White',
            'mileage' => 82000,
            'status' => 'in_training',
            'instructor_id' => $instructor->id,
        ])->assertRedirect();

        $vehicle = Vehicle::firstWhere('plate_number', 'AD-01');
        $this->assertSame('VEH-0001', $vehicle->vehicle_number);
        $this->assertSame($instructor->id, $vehicle->instructor_id);

        // Plate numbers are unique.
        $this->actingAs($this->admin)->post(route('admin.vehicles.store'), [
            'plate_number' => 'AD-01', 'make' => 'Nissan', 'model' => 'Sunny', 'status' => 'available',
        ])->assertSessionHasErrors('plate_number');
    }

    public function test_an_admin_can_manage_suppliers_and_expense_categories(): void
    {
        $this->actingAs($this->admin)->post(route('admin.suppliers.store'), [
            'name' => 'Bakaaro Garage',
            'supplier_type' => 'garage',
            'phone' => '+252613000001',
            'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('suppliers', ['name' => 'Bakaaro Garage', 'supplier_number' => 'SUP-0001']);

        $this->actingAs($this->admin)->post(route('admin.expense-categories.store'), [
            'name' => 'Windscreen Repair',
            'code' => 'windscreen_repair',
            'is_active' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('expense_categories', ['code' => 'windscreen_repair']);
    }

    public function test_an_admin_can_record_a_direct_expense(): void
    {
        $category = ExpenseCategory::where('code', 'office')->first();

        $this->actingAs($this->admin)->post(route('admin.expenses.store'), [
            'expense_category_id' => $category->id,
            'description' => 'Printer paper',
            'amount' => 62.30,
            'expense_date' => Carbon::today()->toDateString(),
            'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertDatabaseHas('company_expenses', [
            'expense_number' => 'EXP-0001',
            'description' => 'Printer paper',
            'amount' => 62.30,
        ]);
    }

    public function test_an_admin_can_issue_a_loan_and_record_a_repayment(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');

        $this->actingAs($this->admin)->post(route('admin.loans.store'), [
            'instructor_id' => $instructor->id,
            'amount' => 300,
            'loan_date' => Carbon::today()->subMonth()->toDateString(),
            'reason' => 'Family emergency advance',
        ])->assertRedirect();

        $loan = InstructorLoan::first();
        $this->assertSame('300.00', $loan->remaining_amount);

        $this->actingAs($this->admin)->post(route('admin.loans.payments.store', $loan), [
            'amount' => 150,
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method' => 'salary_deduction',
        ])->assertRedirect();

        $loan->refresh();
        $this->assertSame('150.00', $loan->remaining_amount);
        $this->assertSame('partially_paid', $loan->status);
        $this->assertSame(150.0, $loan->paid_amount);

        // Overpayment is rejected.
        $this->actingAs($this->admin)->post(route('admin.loans.payments.store', $loan), [
            'amount' => 500,
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method' => 'cash',
        ])->assertSessionHasErrors('amount');
    }

    public function test_an_admin_can_manage_users(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'New Manager',
            'email' => 'manager@example.com',
            'role_id' => Role::where('name', Role::ADMIN)->value('id'),
            'locale' => 'so',
            'is_active' => 1,
            'password' => 'Manager@12345',
            'password_confirmation' => 'Manager@12345',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'manager@example.com', 'locale' => 'so']);
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $this->admin))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'deleted_at' => null]);
    }

    public function test_crud_actions_are_written_to_the_audit_log(): void
    {
        $this->actingAs($this->admin)->post(route('admin.suppliers.store'), [
            'name' => 'Nuur Tyres', 'supplier_type' => 'spare_parts', 'status' => 'active',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'supplier.created',
            'user_id' => $this->admin->id,
        ]);

        $this->assertTrue(AuditLog::where('description', 'like', '%Nuur Tyres%')->exists());
    }

    public function test_search_and_filters_narrow_the_student_list(): void
    {
        $xasan = $this->makeInstructor('Xasan Maxamuud');
        $nasteexo = $this->makeInstructor('Nasteexo Aadan');

        $this->makeStudent('Ilyas Maxamed', $xasan);
        $this->makeStudent('Ahmed Nuur', $nasteexo, ['status' => 'suspended']);

        $this->actingAs($this->admin)
            ->get(route('admin.students.index', ['search' => 'Ilyas']))
            ->assertOk()->assertSee('Ilyas Maxamed')->assertDontSee('Ahmed Nuur');

        $this->actingAs($this->admin)
            ->get(route('admin.students.index', ['instructor_id' => $nasteexo->id]))
            ->assertOk()->assertSee('Ahmed Nuur')->assertDontSee('Ilyas Maxamed');

        $this->actingAs($this->admin)
            ->get(route('admin.students.index', ['status' => 'suspended']))
            ->assertOk()->assertSee('Ahmed Nuur')->assertDontSee('Ilyas Maxamed');
    }
}
