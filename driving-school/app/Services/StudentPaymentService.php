<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recording money a student actually hands over.
 *
 * A student payment IS the company's income entry. This application has no
 * separate income-transaction table and does not want one: the Income &
 * Expenses page, the dashboard and the reports all read company income straight
 * out of `student_payments`, so a payment row and the income it represents are
 * the same fact stored once. That is deliberate, and it is the reason income can
 * never drift from, double-count or outlive the payment behind it.
 *
 * (Expenses are the other way round only because they have their own table:
 * paying a company debt writes a DebtPayment *and* a mirroring CompanyExpense,
 * linked by company_debt_id / debt_payment_id. There is no matching income
 * table for the mirror to be written into, and inventing one would be a second
 * source of truth for the same money.)
 *
 * Everything that records a payment goes through here — the registration form
 * and the Student Payments screen alike — so the accounting behaviour of the
 * first payment and the fifth is the same code, not two similar copies.
 */
class StudentPaymentService
{
    /**
     * Records one payment and returns it.
     *
     * Safe to call from inside an outer transaction: Laravel nests this one as
     * a savepoint, so a failure further along the registration still takes the
     * payment with it.
     */
    public function record(Student $student, array $data, User $actor): StudentPayment
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw new RuntimeException(__('A payment must be greater than zero.'));
        }

        return DB::transaction(function () use ($student, $data, $actor, $amount) {
            $payment = StudentPayment::create([
                'payment_number' => DocumentNumber::next(StudentPayment::class, 'payment_number', 'PAY'),
                'student_id' => $student->id,
                'amount' => $amount,
                'payment_date' => Carbon::parse($data['payment_date'] ?? today())->toDateString(),
                'payment_method' => $data['payment_method'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            AuditLogger::created($payment, "Student payment {$payment->payment_number} of {$payment->amount}");

            return $payment;
        });
    }

    /**
     * The payment taken at the counter when a student is registered.
     *
     * Returns null when nothing was handed over — Amount Paid of 0 (or blank)
     * writes no payment row and therefore no income, and the student simply
     * owes the whole fee.
     *
     * Idempotent per student: the student row is locked and their existing
     * payments checked, so re-running the registration for a student who
     * already has one returns that payment instead of recording the money
     * twice. One payment in, one income entry out, however many times this is
     * called.
     */
    public function recordRegistrationPayment(Student $student, array $data, User $actor): ?StudentPayment
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($student, $data, $actor) {
            Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

            // A student who already has a payment has already been through
            // this; a retry must not add a second one.
            if ($existing = $student->payments()->orderBy('id')->first()) {
                return $existing;
            }

            return $this->record($student, [
                ...$data,
                'payment_date' => $data['payment_date'] ?? $student->start_date ?? today(),
                'notes' => $data['notes'] ?? __('Initial registration payment'),
            ], $actor);
        });
    }
}
