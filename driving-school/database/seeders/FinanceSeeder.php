<?php

namespace Database\Seeders;

use App\Models\CompanyDebt;
use App\Models\CompanyExpense;
use App\Models\ExpenseCategory;
use App\Models\Instructor;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DebtService;
use App\Services\LoanService;
use App\Support\DocumentNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class FinanceSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        // The services write audit entries against the acting user.
        Auth::login($admin);

        /* ---------------------------------------------------------------
         | Suppliers
         | --------------------------------------------------------------- */
        $supplierData = [
            ['Bakaaro Garage', 'garage', '+252613000001'],
            ['Hormuud Petrol Station', 'petrol_station', '+252613000002'],
            ['Shabelle Spare Parts', 'spare_parts', '+252613000003'],
            ['Jubba Office Supplies', 'office_supplier', '+252613000004'],
            ['Nuur Tyres', 'spare_parts', '+252613000005'],
        ];

        $suppliers = [];

        foreach ($supplierData as $index => [$name, $type, $phone]) {
            $suppliers[$index] = Supplier::updateOrCreate(
                ['name' => $name],
                [
                    'supplier_number' => 'SUP-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'supplier_type' => $type,
                    'phone' => $phone,
                    'email' => str($name)->lower()->replace(' ', '')->append('@example.com')->toString(),
                    'address' => 'Mogadishu',
                    'status' => 'active',
                ],
            );
        }

        [$garage, $petrol, $spares, $office, $tyres] = $suppliers;

        $debts = app(DebtService::class);
        $categories = ExpenseCategory::pluck('id', 'code');
        $vehicles = Vehicle::pluck('id', 'plate_number');

        /* ---------------------------------------------------------------
         | THE WORKED EXAMPLE FROM THE SPEC
         |
         | Garage service $500 taken on credit, then paid $200 and $300.
         | At the end: debt $500, payments $500, expenses $500, remaining $0.
         | Crucially, creating the debt books NO expense.
         | --------------------------------------------------------------- */
        if (! CompanyDebt::where('description', 'Garage service — engine overhaul')->exists()) {
            $garageDebt = $debts->createDebt([
                'supplier_id' => $garage->id,
                'expense_category_id' => $categories['garage_service'],
                'vehicle_id' => $vehicles['AD-01'] ?? null,
                'description' => 'Garage service — engine overhaul',
                'original_amount' => 500,
                'debt_date' => Carbon::today()->subDays(40),
                'due_date' => Carbon::today()->subDays(10),
            ], $admin);

            $debts->recordPayment($garageDebt, [
                'amount' => 200,
                'payment_date' => Carbon::today()->subDays(25),
                'payment_method' => 'cash',
                'reference' => 'RCP-001',
            ], $admin);

            $debts->recordPayment($garageDebt, [
                'amount' => 300,
                'payment_date' => Carbon::today()->subDays(5),
                'payment_method' => 'mobile_money',
                'reference' => 'RCP-002',
            ], $admin);
        }

        /* A partly-paid debt and an untouched one, so the dashboard has real
           outstanding balances to show. */
        if (! CompanyDebt::where('description', 'Four new tyres')->exists()) {
            $tyreDebt = $debts->createDebt([
                'supplier_id' => $tyres->id,
                'expense_category_id' => $categories['tires'],
                'vehicle_id' => $vehicles['AD-03'] ?? null,
                'description' => 'Four new tyres',
                'original_amount' => 360,
                'debt_date' => Carbon::today()->subDays(18),
                'due_date' => Carbon::today()->addDays(12),
            ], $admin);

            $debts->recordPayment($tyreDebt, [
                'amount' => 160,
                'payment_date' => Carbon::today()->subDays(6),
                'payment_method' => 'cash',
            ], $admin);
        }

        if (! CompanyDebt::where('description', 'Spare parts — brake pads and filters')->exists()) {
            $debts->createDebt([
                'supplier_id' => $spares->id,
                'expense_category_id' => $categories['spare_parts'],
                'vehicle_id' => $vehicles['AD-02'] ?? null,
                'description' => 'Spare parts — brake pads and filters',
                'original_amount' => 240,
                'debt_date' => Carbon::today()->subDays(7),
                'due_date' => Carbon::today()->addDays(23),
            ], $admin);
        }

        /* ---------------------------------------------------------------
         | Direct cash expenses (no debt involved)
         | --------------------------------------------------------------- */
        $directExpenses = [
            ['fuel', 'Fuel — AD-01', 85.50, 12, 'cash', $petrol->id, 'AD-01'],
            ['fuel', 'Fuel — AD-02', 74.00, 9, 'cash', $petrol->id, 'AD-02'],
            ['fuel', 'Fuel — AD-03', 91.25, 4, 'mobile_money', $petrol->id, 'AD-03'],
            ['oil_change', 'Oil change — AD-04', 45.00, 20, 'cash', $garage->id, 'AD-04'],
            ['car_wash', 'Fleet car wash', 30.00, 15, 'cash', null, null],
            ['office', 'Printer paper and files', 62.30, 22, 'cash', $office->id, null],
            ['electricity', 'Office electricity', 120.00, 8, 'bank_transfer', null, null],
            ['internet', 'Office internet', 55.00, 8, 'bank_transfer', null, null],
            ['rent', 'Office rent', 400.00, 28, 'bank_transfer', null, null],
        ];

        foreach ($directExpenses as [$code, $description, $amount, $daysAgo, $method, $supplierId, $plate]) {
            CompanyExpense::firstOrCreate(
                ['description' => $description, 'expense_date' => Carbon::today()->subDays($daysAgo)],
                [
                    'expense_number' => DocumentNumber::next(CompanyExpense::class, 'expense_number', 'EXP'),
                    'expense_category_id' => $categories[$code],
                    'vehicle_id' => $plate ? ($vehicles[$plate] ?? null) : null,
                    'supplier_id' => $supplierId,
                    'amount' => $amount,
                    'payment_method' => $method,
                    'created_by' => $admin->id,
                ],
            );
        }

        /* ---------------------------------------------------------------
         | Instructor loans — the spec's $300 / $150 / $150 example
         | --------------------------------------------------------------- */
        $loans = app(LoanService::class);
        $xasan = Instructor::where('full_name', 'Xasan Maxamuud')->first();
        $nasteexo = Instructor::where('full_name', 'Nasteexo Aadan')->first();

        if ($xasan && ! $xasan->loans()->exists()) {
            $loan = $loans->create([
                'instructor_id' => $xasan->id,
                'amount' => 300,
                'loan_date' => Carbon::today()->subDays(45),
                'due_date' => Carbon::today()->addDays(15),
                'reason' => 'Family emergency advance',
            ], $admin);

            $loans->recordPayment($loan, [
                'amount' => 150,
                'payment_date' => Carbon::today()->subDays(15),
                'payment_method' => 'salary_deduction',
            ], $admin);
        }

        if ($nasteexo && ! $nasteexo->loans()->exists()) {
            $loans->create([
                'instructor_id' => $nasteexo->id,
                'amount' => 200,
                'loan_date' => Carbon::today()->subDays(20),
                'reason' => 'Advance on salary',
            ], $admin);
        }

        /* ---------------------------------------------------------------
         | Student payments — the school's income
         | --------------------------------------------------------------- */
        foreach (Student::all() as $index => $student) {
            if ($student->payments()->exists()) {
                continue;
            }

            StudentPayment::create([
                'payment_number' => DocumentNumber::next(StudentPayment::class, 'payment_number', 'PAY'),
                'student_id' => $student->id,
                'amount' => 150,
                'payment_date' => $student->start_date,
                'payment_method' => 'cash',
                'reference' => 'Deposit',
                'created_by' => $admin->id,
            ]);

            // Roughly half the students have paid a second instalment.
            if ($index % 2 === 0) {
                StudentPayment::create([
                    'payment_number' => DocumentNumber::next(StudentPayment::class, 'payment_number', 'PAY'),
                    'student_id' => $student->id,
                    'amount' => 150,
                    'payment_date' => $student->start_date->copy()->addDays(20),
                    'payment_method' => 'mobile_money',
                    'reference' => 'Balance',
                    'created_by' => $admin->id,
                ]);
            }
        }

        Auth::logout();
    }
}
