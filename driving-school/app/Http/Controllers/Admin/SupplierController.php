<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Support\DocumentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Supplier::class);

        $suppliers = Supplier::query()
            ->withSum(['companyDebts as outstanding_total' => fn ($q) => $q->whereIn('status', ['outstanding', 'partially_paid', 'overdue'])], 'remaining_amount')
            ->withSum('expenses as expenses_total', 'amount')
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('supplier_number', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($request->filled('supplier_type'), fn ($q) => $q->where('supplier_type', $request->string('supplier_type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.suppliers.index', ['suppliers' => $suppliers]);
    }

    public function create(): View
    {
        $this->authorize('create', Supplier::class);

        return view('admin.suppliers.form', ['supplier' => new Supplier(['status' => 'active', 'supplier_type' => 'garage'])]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = DB::transaction(function () use ($request) {
            $supplier = Supplier::create([
                ...$request->validated(),
                'supplier_number' => DocumentNumber::next(Supplier::class, 'supplier_number', 'SUP'),
            ]);

            AuditLogger::created($supplier, "Supplier {$supplier->name} added");

            return $supplier;
        });

        return redirect()->route('admin.suppliers.show', $supplier)->with('status', __('Supplier added.'));
    }

    /** Supplier statement: expenses, debts, payments and the running balance. */
    public function show(Supplier $supplier): View
    {
        $this->authorize('view', $supplier);

        return view('admin.suppliers.show', [
            'supplier' => $supplier,
            'debts' => $supplier->companyDebts()->withSum('payments as paid_total', 'amount')->latest('debt_date')->get(),
            'expenses' => $supplier->expenses()->with('category')->latest('expense_date')->limit(25)->get(),
            'payments' => $supplier->debtPayments()->latest('payment_date')->limit(25)->get(),
            'totals' => [
                'debts' => (float) $supplier->companyDebts()->sum('original_amount'),
                'outstanding' => $supplier->outstanding_balance,
                'paid' => (float) $supplier->debtPayments()->sum('debt_payments.amount'),
                'expenses' => (float) $supplier->expenses()->sum('amount'),
            ],
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        $this->authorize('update', $supplier);

        return view('admin.suppliers.form', ['supplier' => $supplier]);
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $original = $supplier->getOriginal();
        $supplier->update($request->validated());
        AuditLogger::updated($supplier, "Supplier {$supplier->name} updated", $original);

        return redirect()->route('admin.suppliers.show', $supplier)->with('status', __('Supplier updated.'));
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->authorize('delete', $supplier);

        if ($supplier->companyDebts()->outstanding()->exists()) {
            return back()->withErrors(['supplier' => __('This supplier still has outstanding debts.')]);
        }

        $name = $supplier->name;
        $supplier->update(['status' => 'inactive']);
        $supplier->delete();
        AuditLogger::log('supplier.deleted', $supplier, "Supplier {$name} removed");

        return redirect()->route('admin.suppliers.index')->with('status', __('Supplier removed.'));
    }
}
