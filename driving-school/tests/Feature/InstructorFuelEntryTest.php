<?php

namespace Tests\Feature;

use App\Models\CompanyDebt;
use App\Models\CompanyExpense;
use App\Models\FuelRecord;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DashboardService;
use App\Services\DebtService;
use App\Services\FuelService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * An instructor recording a fill-up.
 *
 * Recording fuel writes into the company ledger — an expense for cash, a debt
 * for credit. The instructor's submission is the authorisation: the record is
 * approved as it is created and posts in the same transaction, by the same
 * FuelService code an admin's Approve has always run. The approval path itself
 * is untouched and still settles records that are already pending.
 */
class InstructorFuelEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $xasanUser;

    private User $nasteexoUser;

    private Instructor $xasan;

    private Instructor $nasteexo;

    private Vehicle $xasansCar;

    private Vehicle $nasteexosCar;

    private Supplier $station;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-13 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->xasanUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Xasan']);
        $this->nasteexoUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Nasteexo']);
        $this->xasan = $this->makeInstructor('Xasan', $this->xasanUser);
        $this->nasteexo = $this->makeInstructor('Nasteexo', $this->nasteexoUser);

        $this->xasansCar = $this->makeVehicle('AA-1111', $this->xasan);
        $this->nasteexosCar = $this->makeVehicle('BB-2222', $this->nasteexo);

        $this->station = Supplier::create([
            'supplier_number' => 'SUP-0001',
            'name' => 'Shabelle Petrol',
            'supplier_type' => 'petrol_station',
            'phone' => '+252611111111',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'vehicle_id' => $this->xasansCar->id,
            'liters' => 20,
            'price_per_liter' => 1.5,
            'fuel_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'submission_token' => (string) Str::uuid(),
        ], $overrides);
    }

    private function submit(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->post(route('instructor.fuel.store'), $this->payload($overrides));
    }

    /* A --------------------------------------------------------------- */
    public function test_an_authorised_instructor_can_open_the_add_fuel_form(): void
    {
        $response = $this->actingAs($this->xasanUser)->get(route('instructor.fuel.create'));

        $response->assertOk()->assertSee('Add Fuel')->assertSee('AA-1111');

        // The selector itself offers no other instructor's vehicle.
        $response->assertDontSee('BB-2222');
        $this->assertSame(
            [$this->xasansCar->id],
            $response->viewData('vehicles')->pluck('id')->all(),
        );
    }

    /* B + C + D ------------------------------------------------------- */
    public function test_a_submission_is_approved_at_once_against_the_right_vehicle_and_instructor(): void
    {
        $this->submit($this->xasanUser)->assertRedirect(route('instructor.fuel.index'));

        $fuel = FuelRecord::firstOrFail();

        $this->assertSame($this->xasansCar->id, $fuel->vehicle_id);
        $this->assertSame($this->xasan->id, $fuel->instructor_id);
        $this->assertSame($this->xasanUser->id, $fuel->created_by);
        $this->assertSame(FuelRecord::APPROVED, $fuel->status);
        // The instructor is the one who authorised it; no admin is invented.
        $this->assertSame($this->xasanUser->id, $fuel->approved_by);
        $this->assertNotNull($fuel->approved_at);
        $this->assertSame(30.0, (float) $fuel->amount);
        $this->assertStringStartsWith('FUEL-', $fuel->fuel_number);
    }

    /* E — a cash fill-up posts its expense as it is recorded. */
    public function test_a_cash_submission_writes_the_company_expense_at_once(): void
    {
        $this->submit($this->xasanUser);

        $fuel = FuelRecord::firstOrFail();

        $this->assertSame(FuelRecord::APPROVED, $fuel->status);
        $this->assertNotNull($fuel->company_expense_id);
        $this->assertNull($fuel->company_debt_id);
        $this->assertSame(1, CompanyExpense::count());
        $this->assertSame(0, CompanyDebt::count());

        $expense = CompanyExpense::findOrFail($fuel->company_expense_id);
        $this->assertSame(30.0, (float) $expense->amount);
        $this->assertSame($this->xasansCar->id, $expense->vehicle_id);
    }

    /* E — and no admin has to press anything. */
    public function test_no_pending_record_is_left_for_an_admin_to_approve(): void
    {
        $this->submit($this->xasanUser);

        $this->assertSame(0, FuelRecord::where('status', FuelRecord::PENDING)->count());

        $this->actingAs($this->admin)->get(route('admin.fuel.index'))->assertOk();
        $this->assertSame(1, FuelRecord::where('status', FuelRecord::APPROVED)->count());
    }

    /* F — credit goes to DebtService, not to an expense, and goes at once. */
    public function test_a_credit_submission_raises_a_company_debt_and_no_expense(): void
    {
        $this->submit($this->xasanUser, [
            'is_credit' => 1,
            'supplier_id' => $this->station->id,
            'payment_method' => 'other',
        ]);

        $fuel = FuelRecord::firstOrFail();

        $this->assertSame(FuelRecord::APPROVED, $fuel->status);
        $this->assertSame(1, CompanyDebt::count());
        $debt = CompanyDebt::findOrFail($fuel->company_debt_id);

        $this->assertNull($fuel->company_expense_id);
        $this->assertSame(30.0, (float) $debt->original_amount);
        $this->assertSame(30.0, (float) $debt->remaining_amount);
        $this->assertSame('outstanding', $debt->status);
        $this->assertSame($this->xasansCar->id, $debt->vehicle_id);
        $this->assertStringContainsString($fuel->fuel_number, $debt->description);

        // A debt is an obligation, never an expense until it is paid.
        $this->assertSame(0, CompanyExpense::count());
    }

    /* G + H — the fuel and its ledger entry stand or fall together. */
    public function test_a_failed_ledger_write_rolls_the_fuel_record_back(): void
    {
        $fuel = app(FuelService::class)->record(
            $this->payload(['is_credit' => 1, 'supplier_id' => $this->station->id, 'payment_method' => 'other'])
                + ['instructor_id' => $this->xasan->id],
            $this->xasanUser,
            requiresApproval: true,
        );

        // A DebtService that cannot write, standing in for a failure mid-post.
        $this->app->bind(DebtService::class, fn () => new class extends DebtService
        {
            public function createDebt(array $data, User $actor): CompanyDebt
            {
                throw new RuntimeException('Ledger unavailable');
            }
        });

        try {
            app(FuelService::class)->approve($fuel, $this->admin);
            $this->fail('The approval should have failed with the ledger down.');
        } catch (RuntimeException $e) {
            $this->assertSame('Ledger unavailable', $e->getMessage());
        }

        $fuel->refresh();

        // The approval is rolled back with the debt it could not write.
        $this->assertSame(FuelRecord::PENDING, $fuel->status);
        $this->assertNull($fuel->approved_by);
        $this->assertNull($fuel->company_debt_id);
        $this->assertSame(0, CompanyDebt::count());
    }

    /* I + J — a foreign vehicle id, posted by hand. */
    public function test_an_instructor_cannot_record_fuel_for_another_instructors_vehicle(): void
    {
        $this->submit($this->xasanUser, ['vehicle_id' => $this->nasteexosCar->id])
            ->assertSessionHasErrors('vehicle_id');

        $this->assertSame(0, FuelRecord::count());
        $this->assertSame(0, CompanyExpense::count());
        $this->assertSame(0, CompanyDebt::count());
    }

    /* K + L + M — validation. */
    public function test_impossible_numbers_and_dates_are_refused(): void
    {
        $cases = [
            ['liters' => 0],
            ['liters' => -5],
            ['price_per_liter' => 0],
            ['price_per_liter' => -1],
            ['fuel_date' => today()->addDay()->toDateString()],
            ['fuel_date' => 'not-a-date'],
            // Credit with no supplier has nobody to owe.
            ['is_credit' => 1, 'supplier_id' => null],
        ];

        foreach ($cases as $case) {
            $this->submit($this->xasanUser, $case)
                ->assertSessionHasErrors(array_keys($case)[0] === 'is_credit' ? 'supplier_id' : array_keys($case)[0]);
        }

        $this->assertSame(0, FuelRecord::count());
    }

    /* N — the same form posted twice. */
    public function test_a_resubmitted_form_does_not_record_the_fuel_twice(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->xasanUser)->post(route('instructor.fuel.store'), $payload)->assertRedirect();
        $this->actingAs($this->xasanUser)->post(route('instructor.fuel.store'), $payload)
            ->assertSessionHasErrors('fuel');

        $this->assertSame(1, FuelRecord::count());
    }

    /** And the index itself refuses it, whatever the application does. */
    public function test_the_database_refuses_a_duplicate_submission_token(): void
    {
        $token = (string) Str::uuid();

        $this->actingAs($this->xasanUser)->post(route('instructor.fuel.store'), $this->payload(['submission_token' => $token]));

        $this->expectException(QueryException::class);

        FuelRecord::create([
            'fuel_number' => 'FUEL-9999',
            'vehicle_id' => $this->xasansCar->id,
            'liters' => 1, 'price_per_liter' => 1, 'amount' => 1,
            'fuel_date' => today(), 'payment_method' => 'cash',
            'submission_token' => $token,
        ]);
    }

    /* O + P — the only instructor-originated debt is a fuel purchase. */
    public function test_an_instructor_has_no_way_to_raise_an_arbitrary_company_debt(): void
    {
        $this->assertFalse($this->xasanUser->can('create', CompanyDebt::class));

        $this->actingAs($this->xasanUser)
            ->post(route('admin.debts.store'), [
                'supplier_id' => $this->station->id,
                'description' => 'Anything at all',
                'original_amount' => 5000,
                'debt_date' => today()->toDateString(),
            ])
            ->assertForbidden();

        $this->assertSame(0, CompanyDebt::count());
    }

    /* Q + R + S — who can see the debt the approval produced. */
    public function test_the_resulting_debt_is_visible_to_its_instructor_and_the_admin_alone(): void
    {
        $this->submit($this->xasanUser, [
            'is_credit' => 1,
            'supplier_id' => $this->station->id,
            'payment_method' => 'other',
        ]);

        $debt = CompanyDebt::firstOrFail();

        $this->assertSame([$debt->id], CompanyDebt::query()->visibleTo($this->xasanUser)->pluck('id')->all());
        $this->assertSame([], CompanyDebt::query()->visibleTo($this->nasteexoUser)->pluck('id')->all());
        $this->assertSame([$debt->id], CompanyDebt::query()->visibleTo($this->admin)->pluck('id')->all());

        $this->actingAs($this->xasanUser)->get(route('instructor.company-debts.show', $debt))->assertOk();
        $this->actingAs($this->nasteexoUser)->get(route('instructor.company-debts.show', $debt))->assertForbidden();
    }

    /* T — the admin's own flow, start to finish, untouched. */
    public function test_the_admin_flow_still_posts_on_save_and_pays_as_before(): void
    {
        $this->actingAs($this->admin)->post(route('admin.fuel.store'), [
            'vehicle_id' => $this->nasteexosCar->id,
            'instructor_id' => $this->nasteexo->id,
            'supplier_id' => $this->station->id,
            'liters' => 40,
            'price_per_liter' => 2,
            'fuel_date' => today()->toDateString(),
            'payment_method' => 'other',
            'is_credit' => 1,
        ])->assertRedirect(route('admin.fuel.index'));

        $fuel = FuelRecord::firstOrFail();

        // No queue for the admin: approved and posted on save, as before.
        $this->assertSame(FuelRecord::APPROVED, $fuel->status);
        $this->assertNotNull($fuel->company_debt_id);

        $debt = CompanyDebt::findOrFail($fuel->company_debt_id);
        $this->assertSame(80.0, (float) $debt->original_amount);

        // And paying it still writes the expense and moves the balance.
        $this->actingAs($this->admin)->post(route('admin.debts.payments.store', $debt), [
            'amount' => 50,
            'payment_date' => today()->toDateString(),
            'payment_method' => 'cash',
        ])->assertRedirect();

        $debt->refresh();
        $this->assertSame(30.0, (float) $debt->remaining_amount);
        $this->assertSame('partially_paid', $debt->status);
        $this->assertSame(50.0, (float) CompanyExpense::sum('amount'));

        $this->actingAs($this->admin)->post(route('admin.debts.cancel', $debt))->assertRedirect();
        $this->assertSame('cancelled', $debt->fresh()->status);
    }

    /** Rejecting refuses the submission and still posts nothing. */
    /**
     * Rejection still works, for the records that are still pending.
     *
     * An instructor's own submissions no longer wait, but admin entries held
     * back and anything left pending from before this change still go through
     * the same approve/reject pair, untouched.
     */
    public function test_a_pending_record_can_still_be_rejected_and_never_reaches_the_ledger(): void
    {
        $fuel = app(FuelService::class)->record(
            $this->payload() + ['instructor_id' => $this->xasan->id],
            $this->xasanUser,
            requiresApproval: true,
        );

        $this->assertSame(FuelRecord::PENDING, $fuel->status);
        $this->assertSame(0, CompanyExpense::count());

        $this->actingAs($this->admin)
            ->post(route('admin.fuel.reject', $fuel), ['rejection_reason' => 'Receipt missing'])
            ->assertRedirect();

        $fuel->refresh();

        $this->assertSame(FuelRecord::REJECTED, $fuel->status);
        $this->assertSame('Receipt missing', $fuel->rejection_reason);
        $this->assertSame(0, CompanyExpense::count());
        $this->assertSame(0, CompanyDebt::count());

        // And it cannot then be approved into the ledger by the back door.
        $this->actingAs($this->admin)->post(route('admin.fuel.approve', $fuel))->assertSessionHasErrors('fuel');
        $this->assertSame(0, CompanyExpense::count());
    }

    /** Approving twice must not charge the company twice. */
    public function test_approving_twice_posts_one_ledger_entry(): void
    {
        $this->submit($this->xasanUser);
        $fuel = FuelRecord::firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.fuel.approve', $fuel));
        $this->actingAs($this->admin)->post(route('admin.fuel.approve', $fuel))->assertSessionHasErrors('fuel');

        $this->assertSame(1, CompanyExpense::count());
    }

    /* U + V + W + Y — everyone else. */
    public function test_nobody_else_can_reach_the_add_fuel_route(): void
    {
        $student = $this->makeUser(Role::STUDENT);

        $this->actingAs($student)->get(route('instructor.fuel.create'))->assertForbidden();
        $this->actingAs($student)->post(route('instructor.fuel.store'), $this->payload())->assertForbidden();

        $this->post('/logout');
        $this->get(route('instructor.fuel.create'))->assertRedirect(route('login'));
        $this->post(route('instructor.fuel.store'), $this->payload())->assertRedirect(route('login'));

        $this->assertSame(0, FuelRecord::count());

        // W — and one instructor's record stays out of the other's reach.
        $this->submit($this->xasanUser);
        $fuel = FuelRecord::firstOrFail();

        $this->actingAs($this->nasteexoUser)->get(route('instructor.fuel.show', $fuel))->assertForbidden();
        $this->actingAs($this->xasanUser)->get(route('instructor.fuel.show', $fuel))->assertOk();
    }

    /* 14 — the audit trail. */
    /* The dashboard counts a cash fill-up the moment it is recorded. */
    public function test_the_dashboard_total_moves_by_the_amount_recorded(): void
    {
        $before = app(DashboardService::class)->adminMetrics()['total_expenses'];

        $this->submit($this->xasanUser);

        $after = app(DashboardService::class)->adminMetrics()['total_expenses'];

        $this->assertSame(round($before + 30.0, 2), round($after, 2));
    }

    /* A credit fill-up is an obligation, so the expense total does not move. */
    public function test_a_credit_fill_up_raises_a_debt_without_moving_the_expense_total(): void
    {
        $before = app(DashboardService::class)->adminMetrics();

        $this->submit($this->xasanUser, [
            'is_credit' => 1,
            'supplier_id' => $this->station->id,
            'payment_method' => 'other',
        ]);

        $after = app(DashboardService::class)->adminMetrics();

        $this->assertSame($before['total_expenses'], $after['total_expenses']);
        $this->assertSame(round($before['outstanding_debt'] + 30.0, 2), round($after['outstanding_debt'], 2));
    }

    /* Recording fuel is not a way to reach students or training. */
    public function test_recording_fuel_touches_no_student_or_training_record(): void
    {
        $tables = ['students', 'attendance', 'student_payments', 'training_sessions',
            'training_queue_entries', 'training_evaluations'];

        $before = [];

        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toArray();
        }

        $this->submit($this->xasanUser, [
            'is_credit' => 1,
            'supplier_id' => $this->station->id,
            'payment_method' => 'other',
        ]);

        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->get()->toArray(),
                "Recording fuel changed {$table}.");
        }
    }

    public function test_every_step_is_written_to_the_audit_log(): void
    {
        $this->submit($this->xasanUser, [
            'is_credit' => 1,
            'supplier_id' => $this->station->id,
            'payment_method' => 'other',
        ]);

        $fuel = FuelRecord::firstOrFail();

        // Created and posted in one step, by the instructor, so the debt is
        // raised under their name rather than an admin's.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fuelrecord.created',
            'auditable_id' => $fuel->id,
            'user_id' => $this->xasanUser->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'debt.created',
            'user_id' => $this->xasanUser->id,
        ]);

        // Nothing was approved separately, because nothing waited.
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'fuelrecord.approved',
            'auditable_id' => $fuel->id,
        ]);
    }
}
