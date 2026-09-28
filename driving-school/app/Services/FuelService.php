<?php

namespace App\Services;

use App\Models\CompanyExpense;
use App\Models\ExpenseCategory;
use App\Models\FuelRecord;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recording fuel, and the ledger entry it produces.
 *
 * This logic used to live inside Admin\FuelController::store(). It moved here
 * unchanged so the instructor console can record fuel through the same path
 * rather than a second copy of the accounting — the only thing that differs
 * between the two callers is whether the record posts on save or waits for an
 * admin.
 *
 * The rule it enforces is the one DebtService documents: fuel paid in cash is
 * an expense the moment it is bought; fuel taken on credit is a company DEBT,
 * because no cash has left yet, and only paying that debt writes an expense.
 */
class FuelService
{
    public function __construct(private readonly DebtService $debts) {}

    /**
     * Records a fuel purchase.
     *
     * `$requiresApproval` is what separates an instructor's submission from an
     * admin's entry: a pending record touches no ledger at all, so nothing is
     * posted to company money until somebody with the authority to approve it
     * says so. An approved record posts in the same transaction that creates
     * it, so there is never a fuel record without its expense or debt, nor a
     * debt without its fuel.
     */
    public function record(array $data, User $actor, bool $requiresApproval = false): FuelRecord
    {
        $this->assertNotAlreadySubmitted($data['submission_token'] ?? null);

        try {
            return DB::transaction(function () use ($data, $actor, $requiresApproval) {
                $amount = round((float) $data['liters'] * (float) $data['price_per_liter'], 2);
                $isCredit = (bool) ($data['is_credit'] ?? false);

                // The mass-assignable half comes from the caller; everything
                // that decides money or authority is forced here, so no request
                // can post itself approved or name its own amount.
                $fuel = new FuelRecord($data);

                $fuel->forceFill([
                    'fuel_number' => DocumentNumber::next(FuelRecord::class, 'fuel_number', 'FUEL'),
                    'amount' => $amount,
                    'is_credit' => $isCredit,
                    'status' => $requiresApproval ? FuelRecord::PENDING : FuelRecord::APPROVED,
                    'approved_by' => $requiresApproval ? null : $actor->id,
                    'approved_at' => $requiresApproval ? null : now(),
                    'created_by' => $actor->id,
                ])->save();

                if (! $requiresApproval) {
                    $this->postToLedger($fuel, $actor);
                }

                AuditLogger::created($fuel, $requiresApproval
                    ? "Fuel record {$fuel->fuel_number} of {$amount} submitted for approval"
                    : "Fuel record {$fuel->fuel_number} of {$amount} added");

                return $fuel->refresh();
            });
        } catch (QueryException $e) {
            // The unique index on submission_token caught a retry the check
            // above could not see, because it arrived at the same moment.
            if (str_contains($e->getMessage(), 'submission_token')) {
                throw new RuntimeException(__('This fuel record has already been submitted.'), 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Approves a pending record and posts it, both in one transaction: the
     * approval and the company money it releases cannot come apart.
     */
    public function approve(FuelRecord $fuel, User $actor): FuelRecord
    {
        return DB::transaction(function () use ($fuel, $actor) {
            $fuel = FuelRecord::query()->lockForUpdate()->findOrFail($fuel->id);

            if ($fuel->status !== FuelRecord::PENDING) {
                throw new RuntimeException(__('Only a pending fuel record can be approved.'));
            }

            $fuel->forceFill([
                'status' => FuelRecord::APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->postToLedger($fuel, $actor);

            AuditLogger::log('fuelrecord.approved', $fuel, __('Fuel record :number approved', [
                'number' => $fuel->fuel_number,
            ]));

            return $fuel->refresh();
        });
    }

    /** Refuses a pending record. Nothing was ever posted, so nothing is undone. */
    public function reject(FuelRecord $fuel, User $actor, ?string $reason = null): FuelRecord
    {
        return DB::transaction(function () use ($fuel, $actor, $reason) {
            $fuel = FuelRecord::query()->lockForUpdate()->findOrFail($fuel->id);

            if ($fuel->status !== FuelRecord::PENDING) {
                throw new RuntimeException(__('Only a pending fuel record can be rejected.'));
            }

            $fuel->forceFill([
                'status' => FuelRecord::REJECTED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            AuditLogger::log('fuelrecord.rejected', $fuel, __('Fuel record :number rejected', [
                'number' => $fuel->fuel_number,
            ]));

            return $fuel->refresh();
        });
    }

    /**
     * The ledger entry itself — an expense for cash, a debt for credit.
     *
     * Must be called inside a transaction: if the debt cannot be written, the
     * fuel record that would have pointed at it has to go with it.
     */
    protected function postToLedger(FuelRecord $fuel, User $actor): void
    {
        if ($fuel->company_expense_id || $fuel->company_debt_id) {
            return; // Already posted; approving twice must not double-charge.
        }

        $categoryId = ExpenseCategory::where('code', 'fuel')->value('id')
            ?? ExpenseCategory::firstOrCreate(['code' => 'fuel'], ['name' => 'Fuel', 'name_so' => 'Shidaal'])->id;

        if ($fuel->is_credit) {
            $debt = $this->debts->createDebt([
                'supplier_id' => $fuel->supplier_id,
                'expense_category_id' => $categoryId,
                'vehicle_id' => $fuel->vehicle_id,
                'description' => __('Fuel on credit — :number', ['number' => $fuel->fuel_number]),
                'original_amount' => $fuel->amount,
                'debt_date' => $fuel->fuel_date,
                'due_date' => null,
                'notes' => $fuel->notes,
            ], $actor);

            $fuel->forceFill(['company_debt_id' => $debt->id])->save();

            return;
        }

        $expense = CompanyExpense::create([
            'expense_number' => DocumentNumber::next(CompanyExpense::class, 'expense_number', 'EXP'),
            'expense_category_id' => $categoryId,
            'vehicle_id' => $fuel->vehicle_id,
            'supplier_id' => $fuel->supplier_id,
            'description' => __('Fuel — :number', ['number' => $fuel->fuel_number]),
            'amount' => $fuel->amount,
            'expense_date' => $fuel->fuel_date,
            'payment_method' => $fuel->payment_method,
            'created_by' => $actor->id,
        ]);

        $fuel->forceFill(['company_expense_id' => $expense->id])->save();
    }

    /**
     * The cheap half of the double-submission guard: a token the form carries,
     * so a refresh or a second click is recognised before any work is done.
     * The unique index behind it is what actually makes it safe.
     */
    protected function assertNotAlreadySubmitted(?string $token): void
    {
        if ($token && FuelRecord::withTrashed()->where('submission_token', $token)->exists()) {
            throw new RuntimeException(__('This fuel record has already been submitted.'));
        }
    }
}
