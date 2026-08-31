<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FuelRecordRequest;
use App\Models\CompanyExpense;
use App\Models\ExpenseCategory;
use App\Models\FuelRecord;
use App\Models\Instructor;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Services\DebtService;
use App\Support\DocumentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FuelController extends Controller
{
    public function __construct(private readonly DebtService $debts) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FuelRecord::class);

        $query = FuelRecord::query()
            ->with(['vehicle', 'instructor', 'supplier'])
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            ->when($request->filled('instructor_id'), fn ($q) => $q->where('instructor_id', $request->integer('instructor_id')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('fuel_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('fuel_date', '<=', $request->date('date_to')));

        return view('admin.fuel.index', [
            'records' => (clone $query)->orderByDesc('fuel_date')->paginate(20)->withQueryString(),
            'vehicles' => Vehicle::orderBy('vehicle_number')->get(),
            'instructors' => Instructor::orderBy('full_name')->get(['id', 'full_name']),
            'suppliers' => Supplier::where('supplier_type', 'petrol_station')->orderBy('name')->get(),
            'totals' => [
                'amount' => (float) (clone $query)->sum('amount'),
                'liters' => (float) (clone $query)->sum('liters'),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', FuelRecord::class);

        return view('admin.fuel.form', [
            'fuel' => new FuelRecord(['fuel_date' => now()->toDateString(), 'payment_method' => 'cash']),
            'vehicles' => Vehicle::orderBy('vehicle_number')->get(),
            'instructors' => Instructor::active()->orderBy('full_name')->get(),
            'suppliers' => Supplier::active()->orderBy('name')->get(),
        ]);
    }

    /**
     * Fuel paid in cash books an expense straight away. Fuel taken on credit
     * books a company DEBT instead — no cash has left the company yet.
     */
    public function store(FuelRecordRequest $request): RedirectResponse
    {
        $this->authorize('create', FuelRecord::class);

        DB::transaction(function () use ($request) {
            $data = $request->validated();
            $amount = round((float) $data['liters'] * (float) $data['price_per_liter'], 2);
            $isCredit = (bool) ($data['is_credit'] ?? false);

            $fuel = FuelRecord::create([
                ...$data,
                'fuel_number' => DocumentNumber::next(FuelRecord::class, 'fuel_number', 'FUEL'),
                'amount' => $amount,
                'is_credit' => $isCredit,
                'created_by' => $request->user()->id,
            ]);

            $categoryId = ExpenseCategory::where('code', 'fuel')->value('id')
                ?? ExpenseCategory::firstOrCreate(['code' => 'fuel'], ['name' => 'Fuel', 'name_so' => 'Shidaal'])->id;

            if ($isCredit) {
                $debt = $this->debts->createDebt([
                    'supplier_id' => $data['supplier_id'],
                    'expense_category_id' => $categoryId,
                    'vehicle_id' => $fuel->vehicle_id,
                    'description' => __('Fuel on credit — :number', ['number' => $fuel->fuel_number]),
                    'original_amount' => $amount,
                    'debt_date' => $data['fuel_date'],
                    'due_date' => null,
                    'notes' => $data['notes'] ?? null,
                ], $request->user());

                $fuel->forceFill(['company_debt_id' => $debt->id])->save();
            } else {
                $expense = CompanyExpense::create([
                    'expense_number' => DocumentNumber::next(CompanyExpense::class, 'expense_number', 'EXP'),
                    'expense_category_id' => $categoryId,
                    'vehicle_id' => $fuel->vehicle_id,
                    'supplier_id' => $fuel->supplier_id,
                    'description' => __('Fuel — :number', ['number' => $fuel->fuel_number]),
                    'amount' => $amount,
                    'expense_date' => $data['fuel_date'],
                    'payment_method' => $data['payment_method'],
                    'created_by' => $request->user()->id,
                ]);

                $fuel->forceFill(['company_expense_id' => $expense->id])->save();
            }

            AuditLogger::created($fuel, "Fuel record {$fuel->fuel_number} of {$amount} added");
        });

        return redirect()->route('admin.fuel.index')->with('status', __('Fuel record saved.'));
    }

    public function show(FuelRecord $fuel): View
    {
        $this->authorize('view', $fuel);

        return view('admin.fuel.show', ['fuel' => $fuel->load(['vehicle', 'instructor', 'supplier', 'expense', 'debt'])]);
    }

    public function edit(FuelRecord $fuel): View
    {
        $this->authorize('update', $fuel);

        return view('admin.fuel.form', [
            'fuel' => $fuel,
            'vehicles' => Vehicle::orderBy('vehicle_number')->get(),
            'instructors' => Instructor::active()->orderBy('full_name')->get(),
            'suppliers' => Supplier::active()->orderBy('name')->get(),
        ]);
    }

    public function update(FuelRecordRequest $request, FuelRecord $fuel): RedirectResponse
    {
        $this->authorize('update', $fuel);

        DB::transaction(function () use ($request, $fuel) {
            $original = $fuel->getOriginal();
            $data = $request->validated();
            $amount = round((float) $data['liters'] * (float) $data['price_per_liter'], 2);

            $fuel->update([...$data, 'amount' => $amount]);

            // Keep the linked expense in step with the fuel record.
            $fuel->expense?->update([
                'amount' => $amount,
                'expense_date' => $data['fuel_date'],
                'vehicle_id' => $fuel->vehicle_id,
                'supplier_id' => $fuel->supplier_id,
                'payment_method' => $data['payment_method'],
            ]);

            AuditLogger::updated($fuel, "Fuel record {$fuel->fuel_number} updated", $original);
        });

        return redirect()->route('admin.fuel.index')->with('status', __('Fuel record updated.'));
    }

    public function destroy(FuelRecord $fuel): RedirectResponse
    {
        $this->authorize('delete', $fuel);

        DB::transaction(function () use ($fuel) {
            $number = $fuel->fuel_number;

            if ($fuel->debt && $fuel->debt->payments()->exists()) {
                abort(422, __('This fuel debt has payments and cannot be removed.'));
            }

            $fuel->expense?->delete();
            $fuel->debt?->delete();
            $fuel->delete();

            AuditLogger::log('fuelrecord.deleted', $fuel, "Fuel record {$number} removed");
        });

        return back()->with('status', __('Fuel record removed.'));
    }
}
