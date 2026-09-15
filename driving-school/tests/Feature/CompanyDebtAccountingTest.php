<?php

namespace Tests\Feature;

use App\Models\CompanyDebt;
use App\Models\CompanyExpense;
use App\Models\DebtPayment;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The mandatory financial test from the specification.
 *
 * A $500 garage debt paid in two instalments must end up as:
 *   debt $500, payments $500, expenses $500, remaining $0, status paid.
 *
 * And critically: creating the debt must book NO expense at all.
 */
class CompanyDebtAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $garage;

    private DebtService $debts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->debts = app(DebtService::class);

        $this->garage = Supplier::create([
            'supplier_number' => 'SUP-0001',
            'name' => 'Bakaaro Garage',
            'supplier_type' => 'garage',
            'phone' => '+252613000001',
            'status' => 'active',
        ]);
    }

    private function createGarageDebt(float $amount = 500): CompanyDebt
    {
        return $this->debts->createDebt([
            'supplier_id' => $this->garage->id,
            'expense_category_id' => ExpenseCategory::where('code', 'garage_service')->value('id'),
            'description' => 'Garage service',
            'original_amount' => $amount,
            'debt_date' => Carbon::today()->subDays(30),
            'due_date' => Carbon::today()->addDays(30),
        ], $this->admin);
    }

    public function test_creating_a_debt_books_no_expense(): void
    {
        $debt = $this->createGarageDebt(500);

        $this->assertSame('500.00', $debt->original_amount);
        $this->assertSame('500.00', $debt->remaining_amount);
        $this->assertSame('outstanding', $debt->status);

        // The whole point of the rule: an unpaid obligation is not cash spent.
        $this->assertSame(0, CompanyExpense::count());
        $this->assertSame(0.0, (float) CompanyExpense::sum('amount'));
    }

    public function test_a_partial_payment_books_an_expense_of_exactly_that_amount(): void
    {
        $debt = $this->createGarageDebt(500);

        $this->debts->recordPayment($debt, [
            'amount' => 200,
            'payment_date' => Carbon::today()->subDays(10),
            'payment_method' => 'cash',
        ], $this->admin);

        $debt->refresh();

        $this->assertSame('500.00', $debt->original_amount);
        $this->assertSame(200.0, (float) $debt->payments()->sum('amount'));
        $this->assertSame(200.0, (float) CompanyExpense::sum('amount'));
        $this->assertSame('300.00', $debt->remaining_amount);
        $this->assertSame('partially_paid', $debt->status);
    }

    public function test_the_full_worked_example_from_the_specification(): void
    {
        $debt = $this->createGarageDebt(500);

        $this->debts->recordPayment($debt, [
            'amount' => 200, 'payment_date' => Carbon::today()->subDays(10), 'payment_method' => 'cash',
        ], $this->admin);

        $this->debts->recordPayment($debt->fresh(), [
            'amount' => 300, 'payment_date' => Carbon::today(), 'payment_method' => 'cash',
        ], $this->admin);

        $debt->refresh();

        $this->assertSame(500.0, (float) $debt->original_amount, 'Total debt');
        $this->assertSame(500.0, (float) DebtPayment::sum('amount'), 'Total payments');
        $this->assertSame(500.0, (float) CompanyExpense::sum('amount'), 'Total expenses');
        $this->assertSame(0.0, (float) $debt->remaining_amount, 'Remaining debt');
        $this->assertSame('paid', $debt->status);
        $this->assertSame(2, $debt->payments()->count());
    }

    public function test_every_payment_produces_exactly_one_linked_expense(): void
    {
        $debt = $this->createGarageDebt(500);

        $payment = $this->debts->recordPayment($debt, [
            'amount' => 200, 'payment_date' => Carbon::today(), 'payment_method' => 'cash',
        ], $this->admin);

        $expense = $payment->expense;

        $this->assertNotNull($expense);
        $this->assertSame(200.0, (float) $expense->amount);
        $this->assertSame($debt->id, $expense->company_debt_id);
        $this->assertSame($this->garage->id, $expense->supplier_id);
        $this->assertTrue($expense->is_system_generated);
    }

    public function test_a_payment_larger_than_the_remaining_debt_is_rejected(): void
    {
        $debt = $this->createGarageDebt(500);

        $this->expectException(RuntimeException::class);

        $this->debts->recordPayment($debt, [
            'amount' => 600, 'payment_date' => Carbon::today(), 'payment_method' => 'cash',
        ], $this->admin);
    }

    public function test_nothing_is_written_when_a_payment_is_rejected(): void
    {
        $debt = $this->createGarageDebt(500);

        try {
            $this->debts->recordPayment($debt, [
                'amount' => 600, 'payment_date' => Carbon::today(), 'payment_method' => 'cash',
            ], $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        // The transaction must leave no payment and no expense behind.
        $this->assertSame(0, DebtPayment::count());
        $this->assertSame(0, CompanyExpense::count());
        $this->assertSame('500.00', $debt->fresh()->remaining_amount);
    }

    public function test_reversing_a_payment_removes_its_expense_and_restores_the_balance(): void
    {
        $debt = $this->createGarageDebt(500);

        $payment = $this->debts->recordPayment($debt, [
            'amount' => 200, 'payment_date' => Carbon::today(), 'payment_method' => 'cash',
        ], $this->admin);

        $this->debts->deletePayment($payment, $this->admin);

        $debt->refresh();

        $this->assertSame('500.00', $debt->remaining_amount);
        $this->assertSame('outstanding', $debt->status);
        $this->assertSame(0.0, (float) CompanyExpense::sum('amount'));
    }

    public function test_the_supplier_balance_reflects_the_unpaid_portion(): void
    {
        $debt = $this->createGarageDebt(500);

        $this->assertSame(500.0, $this->garage->fresh()->outstanding_balance);

        $this->debts->recordPayment($debt, [
            'amount' => 200, 'payment_date' => Carbon::today(), 'payment_method' => 'cash',
        ], $this->admin);

        $this->assertSame(300.0, $this->garage->fresh()->outstanding_balance);
    }

    /* ----------------------------------------------------------------
     | Through the HTTP layer
     | ---------------------------------------------------------------- */

    public function test_an_admin_can_record_a_debt_payment_through_the_ui(): void
    {
        $debt = $this->createGarageDebt(500);

        $this->actingAs($this->admin)
            ->post(route('admin.debts.payments.store', $debt), [
                'amount' => 200,
                'payment_date' => Carbon::today()->toDateString(),
                'payment_method' => 'cash',
            ])
            ->assertRedirect(route('admin.debts.show', $debt));

        $this->assertSame('300.00', $debt->fresh()->remaining_amount);
        $this->assertSame(200.0, (float) CompanyExpense::sum('amount'));
    }

    public function test_the_ui_rejects_an_overpayment_with_a_validation_error(): void
    {
        $debt = $this->createGarageDebt(500);

        $this->actingAs($this->admin)
            ->post(route('admin.debts.payments.store', $debt), [
                'amount' => 900,
                'payment_date' => Carbon::today()->toDateString(),
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, DebtPayment::count());
    }

    public function test_a_system_generated_expense_cannot_be_edited_or_deleted(): void
    {
        $debt = $this->createGarageDebt(500);

        $payment = $this->debts->recordPayment($debt, [
            'amount' => 200, 'payment_date' => Carbon::today(), 'payment_method' => 'cash',
        ], $this->admin);

        $expense = $payment->expense;

        $this->actingAs($this->admin)->get(route('admin.expenses.edit', $expense))->assertForbidden();
        $this->actingAs($this->admin)->delete(route('admin.expenses.destroy', $expense))->assertForbidden();

        $this->assertSame(1, CompanyExpense::count());
    }

    public function test_an_instructor_cannot_reach_any_debt_or_expense_endpoint(): void
    {
        $instructorUser = $this->makeUser(Role::INSTRUCTOR);
        $this->makeInstructor('Xasan Maxamuud', $instructorUser);

        $debt = $this->createGarageDebt(500);

        $this->actingAs($instructorUser)->get(route('admin.debts.show', $debt))->assertForbidden();
        $this->actingAs($instructorUser)
            ->post(route('admin.debts.payments.store', $debt), [
                'amount' => 100,
                'payment_date' => Carbon::today()->toDateString(),
                'payment_method' => 'cash',
            ])
            ->assertForbidden();

        $this->assertSame(0, DebtPayment::count());
    }
}
