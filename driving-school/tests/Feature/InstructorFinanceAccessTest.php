<?php

namespace Tests\Feature;

use App\Models\CompanyDebt;
use App\Models\FuelRecord;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fuel and Company Debts for instructors: read only, and only what reaches them.
 *
 * Fuel reaches an instructor two ways the table already records — the fill-up
 * was logged against them, or it was for a vehicle assigned to them. A company
 * debt names no instructor at all (it is money owed to a supplier), so it
 * reaches them only through a vehicle of theirs or through fuel they took on
 * credit. Everything else in the company ledger stays out of sight, and every
 * write on both stays with the admin.
 */
class InstructorFinanceAccessTest extends TestCase
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

    private function fuel(string $number, Vehicle $vehicle, ?Instructor $instructor, array $extra = []): FuelRecord
    {
        return FuelRecord::create(array_merge([
            'fuel_number' => $number,
            'vehicle_id' => $vehicle->id,
            'instructor_id' => $instructor?->id,
            'supplier_id' => $this->station->id,
            'liters' => 20,
            'price_per_liter' => 1.5,
            'amount' => 30,
            'fuel_date' => today(),
            'payment_method' => 'cash',
        ], $extra));
    }

    private function debt(string $number, array $extra = []): CompanyDebt
    {
        return CompanyDebt::create(array_merge([
            'debt_number' => $number,
            'supplier_id' => $this->station->id,
            'description' => 'Fuel on credit',
            'original_amount' => 500,
            'remaining_amount' => 500,
            'debt_date' => today(),
            'status' => 'outstanding',
        ], $extra));
    }

    /* A + B — Fuel ---------------------------------------------------- */
    public function test_an_instructor_sees_their_own_fuel_and_their_vehicles_fuel(): void
    {
        $own = $this->fuel('FUEL-0001', $this->xasansCar, $this->xasan);
        $forTheirCar = $this->fuel('FUEL-0002', $this->xasansCar, null);
        $someoneElses = $this->fuel('FUEL-0003', $this->nasteexosCar, $this->nasteexo);

        $response = $this->actingAs($this->xasanUser)->get(route('instructor.fuel.index'));

        $response->assertOk()
            ->assertSee('FUEL-0001')
            ->assertSee('FUEL-0002')
            ->assertDontSee('FUEL-0003');

        $this->assertEqualsCanonicalizing(
            [$own->id, $forTheirCar->id],
            FuelRecord::query()->visibleTo($this->xasanUser)->pluck('id')->all(),
        );

        $this->assertSame(60.0, (float) FuelRecord::query()->visibleTo($this->xasanUser)->sum('amount'));
        $this->assertNotNull($someoneElses->id);
    }

    /* C — Fuel: another instructor's record is out of reach, by id too. */
    public function test_an_instructor_cannot_open_another_instructors_fuel_record(): void
    {
        $theirs = $this->fuel('FUEL-0001', $this->xasansCar, $this->xasan);
        $someoneElses = $this->fuel('FUEL-0003', $this->nasteexosCar, $this->nasteexo);

        $this->actingAs($this->xasanUser)->get(route('instructor.fuel.show', $theirs))->assertOk();
        $this->actingAs($this->xasanUser)->get(route('instructor.fuel.show', $someoneElses))->assertForbidden();
    }

    /* D — Fuel: no admin action is reachable. */
    public function test_an_instructor_cannot_create_edit_or_delete_fuel(): void
    {
        $record = $this->fuel('FUEL-0001', $this->xasansCar, $this->xasan);

        $this->assertFalse($this->xasanUser->can('create', FuelRecord::class));
        $this->assertFalse($this->xasanUser->can('update', $record));
        $this->assertFalse($this->xasanUser->can('delete', $record));

        $this->actingAs($this->xasanUser)->get(route('admin.fuel.create'))->assertForbidden();
        $this->actingAs($this->xasanUser)->get(route('admin.fuel.index'))->assertForbidden();
        $this->actingAs($this->xasanUser)->delete(route('admin.fuel.destroy', $record))->assertForbidden();

        $this->assertSame(1, FuelRecord::count());
    }

    /* F + G — Company debts ------------------------------------------- */
    public function test_an_instructor_sees_debts_for_their_vehicle_and_their_fuel_credit(): void
    {
        $onTheirCar = $this->debt('DEBT-0001', ['vehicle_id' => $this->xasansCar->id]);

        $theirFuelCredit = $this->debt('DEBT-0002');
        $this->fuel('FUEL-0001', $this->xasansCar, $this->xasan, [
            'is_credit' => true,
            'company_debt_id' => $theirFuelCredit->id,
        ]);

        $this->debt('DEBT-0003', ['vehicle_id' => $this->nasteexosCar->id]);
        $this->debt('DEBT-0004', ['description' => 'Office rent']);

        $response = $this->actingAs($this->xasanUser)->get(route('instructor.company-debts.index'));

        $response->assertOk()
            ->assertSee('DEBT-0001')
            ->assertSee('DEBT-0002')
            ->assertDontSee('DEBT-0003')
            ->assertDontSee('DEBT-0004');

        $this->assertEqualsCanonicalizing(
            [$onTheirCar->id, $theirFuelCredit->id],
            CompanyDebt::query()->visibleTo($this->xasanUser)->pluck('id')->all(),
        );
    }

    /* H — a debt that is none of their business, asked for by id. */
    public function test_an_instructor_cannot_open_an_unrelated_company_debt(): void
    {
        $rent = $this->debt('DEBT-0004', ['description' => 'Office rent']);
        $othersCar = $this->debt('DEBT-0003', ['vehicle_id' => $this->nasteexosCar->id]);
        $theirs = $this->debt('DEBT-0001', ['vehicle_id' => $this->xasansCar->id]);

        $this->actingAs($this->xasanUser)->get(route('instructor.company-debts.show', $theirs))->assertOk();
        $this->actingAs($this->xasanUser)->get(route('instructor.company-debts.show', $rent))->assertForbidden();
        $this->actingAs($this->xasanUser)->get(route('instructor.company-debts.show', $othersCar))->assertForbidden();
    }

    /* I — no admin debt action is reachable. */
    public function test_an_instructor_cannot_raise_edit_pay_or_cancel_a_debt(): void
    {
        $debt = $this->debt('DEBT-0001', ['vehicle_id' => $this->xasansCar->id]);

        $this->assertFalse($this->xasanUser->can('create', CompanyDebt::class));
        $this->assertFalse($this->xasanUser->can('update', $debt));
        $this->assertFalse($this->xasanUser->can('delete', $debt));

        $this->actingAs($this->xasanUser)->get(route('admin.debts.index'))->assertForbidden();
        $this->actingAs($this->xasanUser)->get(route('admin.debts.create'))->assertForbidden();
        $this->actingAs($this->xasanUser)->post(route('admin.debts.payments.store', $debt), ['amount' => 100])->assertForbidden();
        $this->actingAs($this->xasanUser)->post(route('admin.debts.cancel', $debt))->assertForbidden();

        $this->assertSame('outstanding', $debt->fresh()->status);
        $this->assertSame(500.0, (float) $debt->fresh()->remaining_amount);
    }

    /* N + 12 — nobody else gets in, by menu or by URL. */
    public function test_students_and_signed_out_visitors_cannot_reach_either_page(): void
    {
        $student = $this->makeUser(Role::STUDENT);

        foreach (['instructor.fuel.index', 'instructor.company-debts.index'] as $route) {
            $this->actingAs($student)->get(route($route))->assertForbidden();
            $this->post('/logout');
            $this->get(route($route))->assertRedirect(route('login'));
        }

        $this->assertFalse($student->can('viewAny', FuelRecord::class));
        $this->assertFalse($student->can('viewAny', CompanyDebt::class));
    }

    /* K + L + M — navigation. */
    public function test_the_menu_carries_both_entries_and_highlights_the_open_one(): void
    {
        $dashboard = $this->actingAs($this->xasanUser)->get(route('instructor.dashboard'));

        $dashboard->assertOk()
            ->assertSee(route('instructor.fuel.index'))
            ->assertSee(route('instructor.company-debts.index'))
            ->assertSee(route('instructor.loans.index'))
            ->assertSee('My Finance');

        // The open page's link carries the active class; the others do not.
        $fuelPage = $this->actingAs($this->xasanUser)->get(route('instructor.fuel.index'));
        $fuelPage->assertOk();

        $this->assertStringContainsString(
            'href="'.route('instructor.fuel.index').'" class="nav-link nav-link-active"',
            $fuelPage->getContent(),
        );
        $this->assertStringContainsString(
            'href="'.route('instructor.company-debts.index').'" class="nav-link"',
            $fuelPage->getContent(),
        );

        $debtPage = $this->actingAs($this->xasanUser)->get(route('instructor.company-debts.index'));

        $this->assertStringContainsString(
            'href="'.route('instructor.company-debts.index').'" class="nav-link nav-link-active"',
            $debtPage->getContent(),
        );
    }

    /* 9 — the empty state, not an empty table. */
    public function test_both_pages_say_so_when_there_is_genuinely_nothing(): void
    {
        $this->actingAs($this->nasteexoUser)
            ->get(route('instructor.fuel.index'))
            ->assertOk()
            ->assertSee('No fuel records found.');

        $this->actingAs($this->nasteexoUser)
            ->get(route('instructor.company-debts.index'))
            ->assertOk()
            ->assertSee('No company debts found.');
    }

    /* E + J — the admin's own view is untouched. */
    public function test_the_admin_still_sees_and_manages_everything(): void
    {
        $this->fuel('FUEL-0001', $this->xasansCar, $this->xasan);
        $this->fuel('FUEL-0003', $this->nasteexosCar, $this->nasteexo);
        $this->debt('DEBT-0001', ['vehicle_id' => $this->xasansCar->id]);
        $this->debt('DEBT-0004', ['description' => 'Office rent']);

        $this->actingAs($this->admin)->get(route('admin.fuel.index'))
            ->assertOk()->assertSee('FUEL-0001')->assertSee('FUEL-0003');

        $this->actingAs($this->admin)->get(route('admin.debts.index'))
            ->assertOk()->assertSee('DEBT-0001')->assertSee('DEBT-0004');

        $this->assertSame(2, FuelRecord::query()->visibleTo($this->admin)->count());
        $this->assertSame(2, CompanyDebt::query()->visibleTo($this->admin)->count());
        $this->assertTrue($this->admin->can('create', FuelRecord::class));
        $this->assertTrue($this->admin->can('create', CompanyDebt::class));
    }
}
