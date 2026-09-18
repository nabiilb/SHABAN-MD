<?php

namespace App\Services;

use App\Models\CompanyDebt;
use App\Models\CompanyExpense;
use App\Models\DebtPayment;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The core accounting rule of the system.
 *
 * Creating a company debt records an OBLIGATION, not an expense — no cash has
 * left the company yet. A company expense is only ever written when a payment
 * is actually made. So a $500 garage debt paid in two instalments of $200 and
 * $300 produces $500 of debt, $500 of payments and $500 of expenses in total,
 * but nothing is expensed at the moment the debt is created.
 */
class DebtService
{
    public function createDebt(array $data, User $actor): CompanyDebt
    {
        return DB::transaction(function () use ($data, $actor) {
            $debt = CompanyDebt::create([
                'debt_number' => DocumentNumber::next(CompanyDebt::class, 'debt_number', 'DEBT'),
                'supplier_id' => $data['supplier_id'],
                'expense_category_id' => $data['expense_category_id'] ?? null,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'description' => $data['description'],
                'original_amount' => $data['original_amount'],
                // No payments yet: the whole obligation is still outstanding.
                'remaining_amount' => $data['original_amount'],
                'debt_date' => $data['debt_date'],
                'due_date' => $data['due_date'] ?? null,
                'status' => 'outstanding',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            AuditLogger::log(
                'debt.created',
                $debt,
                "Company debt {$debt->debt_number} of {$debt->original_amount} recorded",
                null,
                $debt->getAttributes(),
            );

            return $debt;
        });
    }

    /**
     * Records a payment against a debt and, in the SAME transaction, writes the
     * matching company expense and reduces the outstanding balance.
     */
    public function recordPayment(CompanyDebt $debt, array $data, User $actor): DebtPayment
    {
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw new RuntimeException(__('Payment amount must be greater than zero.'));
        }

        if ($debt->status === 'cancelled') {
            throw new RuntimeException(__('A cancelled debt cannot be paid.'));
        }

        if ($amount > round((float) $debt->remaining_amount, 2) + 0.001) {
            throw new RuntimeException(__('Payment cannot exceed the remaining debt of :amount.', [
                'amount' => number_format((float) $debt->remaining_amount, 2),
            ]));
        }

        return DB::transaction(function () use ($debt, $data, $actor, $amount) {
            // Lock the debt row so two concurrent payments cannot overdraw it.
            $debt = CompanyDebt::query()->lockForUpdate()->findOrFail($debt->id);

            if ($amount > round((float) $debt->remaining_amount, 2) + 0.001) {
                throw new RuntimeException(__('Payment cannot exceed the remaining debt of :amount.', [
                    'amount' => number_format((float) $debt->remaining_amount, 2),
                ]));
            }

            // 1. The payment itself.
            $payment = DebtPayment::create([
                'company_debt_id' => $debt->id,
                'amount' => $amount,
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            // 2. The cash actually leaving the company — this is the expense.
            $expense = CompanyExpense::create([
                'expense_number' => DocumentNumber::next(CompanyExpense::class, 'expense_number', 'EXP'),
                'expense_category_id' => $debt->expense_category_id ?: $this->fallbackCategoryId(),
                'vehicle_id' => $debt->vehicle_id,
                'supplier_id' => $debt->supplier_id,
                'company_debt_id' => $debt->id,
                'debt_payment_id' => $payment->id,
                'description' => __('Payment for debt :number — :description', [
                    'number' => $debt->debt_number,
                    'description' => $debt->description,
                ]),
                'amount' => $amount,
                'expense_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'] ?? 'cash',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            // 3 + 4. Reduce the balance and move the status along.
            $before = ['remaining_amount' => $debt->remaining_amount, 'status' => $debt->status];
            $debt->recalculate();

            AuditLogger::log(
                'debt.payment',
                $debt,
                __('Paid :amount against debt :number', [
                    'amount' => number_format($amount, 2),
                    'number' => $debt->debt_number,
                ]),
                $before,
                [
                    'remaining_amount' => $debt->remaining_amount,
                    'status' => $debt->status,
                    'payment_id' => $payment->id,
                    'expense_id' => $expense->id,
                ],
            );

            return $payment->fresh(['expense', 'companyDebt']);
        });
    }

    /** Reverses a payment: drops the generated expense and restores the balance. */
    public function deletePayment(DebtPayment $payment, User $actor): void
    {
        DB::transaction(function () use ($payment) {
            $debt = CompanyDebt::query()->lockForUpdate()->findOrFail($payment->company_debt_id);

            $payment->expense?->delete();
            $payment->delete();
            $debt->recalculate();

            AuditLogger::log(
                'debt.payment_reversed',
                $debt,
                __('Reversed payment of :amount on debt :number', [
                    'amount' => number_format((float) $payment->amount, 2),
                    'number' => $debt->debt_number,
                ]),
            );
        });
    }

    protected function fallbackCategoryId(): int
    {
        return ExpenseCategory::query()
            ->firstOrCreate(['code' => 'other'], ['name' => 'Other', 'name_so' => 'Kale'])
            ->id;
    }

    /** Marks every past-due unpaid debt as overdue. */
    public function refreshOverdue(): int
    {
        return CompanyDebt::query()
            ->whereIn('status', ['outstanding', 'partially_paid'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', Carbon::today())
            ->update(['status' => 'overdue']);
    }
}
