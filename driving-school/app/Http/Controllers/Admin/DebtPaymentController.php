<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DebtPaymentRequest;
use App\Models\CompanyDebt;
use App\Models\DebtPayment;
use App\Services\DebtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DebtPaymentController extends Controller
{
    public function __construct(private readonly DebtService $debts) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DebtPayment::class);

        $payments = DebtPayment::query()
            ->with(['companyDebt.supplier', 'expense', 'creator'])
            ->when($request->filled('supplier_id'), fn ($q) => $q->whereHas('companyDebt', fn ($d) => $d->where('supplier_id', $request->integer('supplier_id'))))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->string('payment_method')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('payment_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('payment_date', '<=', $request->date('date_to')))
            ->orderByDesc('payment_date')
            ->paginate(20)
            ->withQueryString();

        return view('admin.debt-payments.index', ['payments' => $payments]);
    }

    /**
     * Records a payment. Creating the payment, writing the company expense and
     * reducing the debt all happen inside one transaction — see DebtService.
     */
    public function store(DebtPaymentRequest $request, CompanyDebt $debt): RedirectResponse
    {
        $this->authorize('create', DebtPayment::class);

        $payment = $this->debts->recordPayment($debt, $request->validated(), $request->user());

        return redirect()
            ->route('admin.debts.show', $debt)
            ->with('status', __('Payment of :amount recorded — a company expense of the same amount was booked.', [
                'amount' => number_format((float) $payment->amount, 2),
            ]));
    }

    public function destroy(Request $request, CompanyDebt $debt, DebtPayment $payment): RedirectResponse
    {
        $this->authorize('delete', $payment);

        abort_unless($payment->company_debt_id === $debt->id, 404);

        $this->debts->deletePayment($payment, $request->user());

        return back()->with('status', __('Payment reversed and its expense removed.'));
    }
}
