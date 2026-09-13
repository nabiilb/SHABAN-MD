<?php

namespace App\Services;

use App\Models\InstructorLoan;
use App\Models\InstructorLoanPayment;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LoanService
{
    public function create(array $data, User $actor): InstructorLoan
    {
        return DB::transaction(function () use ($data, $actor) {
            $loan = InstructorLoan::create([
                'loan_number' => DocumentNumber::next(InstructorLoan::class, 'loan_number', 'LOAN'),
                'instructor_id' => $data['instructor_id'],
                'amount' => $data['amount'],
                'remaining_amount' => $data['amount'],
                'loan_date' => $data['loan_date'],
                'due_date' => $data['due_date'] ?? null,
                'reason' => $data['reason'] ?? null,
                'status' => 'outstanding',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            AuditLogger::log('loan.created', $loan, "Loan {$loan->loan_number} of {$loan->amount} issued", null, $loan->getAttributes());

            return $loan;
        });
    }

    public function recordPayment(InstructorLoan $loan, array $data, User $actor): InstructorLoanPayment
    {
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw new RuntimeException(__('Payment amount must be greater than zero.'));
        }

        return DB::transaction(function () use ($loan, $data, $actor, $amount) {
            $loan = InstructorLoan::query()->lockForUpdate()->findOrFail($loan->id);

            if ($amount > round((float) $loan->remaining_amount, 2) + 0.001) {
                throw new RuntimeException(__('Payment cannot exceed the remaining loan of :amount.', [
                    'amount' => number_format((float) $loan->remaining_amount, 2),
                ]));
            }

            $payment = InstructorLoanPayment::create([
                'instructor_loan_id' => $loan->id,
                'amount' => $amount,
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $loan->recalculate();

            AuditLogger::log('loan.payment', $loan, __('Loan repayment of :amount recorded', [
                'amount' => number_format($amount, 2),
            ]));

            return $payment;
        });
    }
}
