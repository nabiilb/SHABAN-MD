<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyDebtRequest;
use App\Models\CompanyDebt;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Services\DebtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyDebtController extends Controller
{
    public function __construct(private readonly DebtService $debts) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CompanyDebt::class);

        $query = CompanyDebt::query()
            ->with('supplier')
            ->withSum('payments as paid_total', 'amount')
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('debt_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('debt_date', '<=', $request->date('date_to')))
            ->when($request->filled('due_before'), fn ($q) => $q->whereDate('due_date', '<=', $request->date('due_before')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($sub) => $sub->where('description', 'like', $term)->orWhere('debt_number', 'like', $term));
            });

        return view('admin.debts.index', [
            'debts' => (clone $query)->orderByDesc('debt_date')->paginate(15)->withQueryString(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'totals' => [
                'original' => (float) (clone $query)->sum('original_amount'),
                'remaining' => (float) (clone $query)->sum('remaining_amount'),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', CompanyDebt::class);

        return view('admin.debts.form', [
            'debt' => new CompanyDebt(['debt_date' => now()->toDateString()]),
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('sort_order')->get(),
            'vehicles' => Vehicle::orderBy('vehicle_number')->get(),
        ]);
    }

    public function store(CompanyDebtRequest $request): RedirectResponse
    {
        $this->authorize('create', CompanyDebt::class);

        $debt = $this->debts->createDebt($request->validated(), $request->user());

        return redirect()
            ->route('admin.debts.show', $debt)
            ->with('status', __('Debt :number recorded. No expense is booked until it is paid.', ['number' => $debt->debt_number]));
    }

    public function show(CompanyDebt $debt): View
    {
        $this->authorize('view', $debt);

        return view('admin.debts.show', [
            'debt' => $debt->load(['supplier', 'category', 'vehicle', 'creator']),
            'payments' => $debt->payments()->with(['expense', 'creator'])->orderByDesc('payment_date')->get(),
            'expenses' => $debt->expenses()->latest('expense_date')->get(),
        ]);
    }

    public function edit(CompanyDebt $debt): View
    {
        $this->authorize('update', $debt);

        return view('admin.debts.form', [
            'debt' => $debt,
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('sort_order')->get(),
            'vehicles' => Vehicle::orderBy('vehicle_number')->get(),
        ]);
    }

    public function update(CompanyDebtRequest $request, CompanyDebt $debt): RedirectResponse
    {
        $this->authorize('update', $debt);

        $paid = (float) $debt->payments()->sum('amount');

        if ((float) $request->input('original_amount') < $paid) {
            return back()->withInput()->withErrors([
                'original_amount' => __('The debt cannot be lower than the :amount already paid.', [
                    'amount' => number_format($paid, 2),
                ]),
            ]);
        }

        $original = $debt->getOriginal();
        $debt->update($request->safe()->except('status'));
        $debt->recalculate();

        AuditLogger::updated($debt, "Debt {$debt->debt_number} updated", $original);

        return redirect()->route('admin.debts.show', $debt)->with('status', __('Debt updated.'));
    }

    public function destroy(CompanyDebt $debt): RedirectResponse
    {
        $this->authorize('delete', $debt);

        if ($debt->payments()->exists()) {
            return back()->withErrors(['debt' => __('Reverse the payments on this debt before removing it.')]);
        }

        $number = $debt->debt_number;
        $debt->delete();
        AuditLogger::log('debt.deleted', $debt, "Debt {$number} removed");

        return redirect()->route('admin.debts.index')->with('status', __('Debt removed.'));
    }

    public function cancel(CompanyDebt $debt): RedirectResponse
    {
        $this->authorize('update', $debt);

        $debt->update(['status' => 'cancelled']);
        AuditLogger::log('debt.cancelled', $debt, "Debt {$debt->debt_number} cancelled");

        return back()->with('status', __('Debt cancelled.'));
    }
}
