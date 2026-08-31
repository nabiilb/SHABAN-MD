<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\InstructorLoanRequest;
use App\Models\Instructor;
use App\Models\InstructorLoan;
use App\Models\InstructorLoanPayment;
use App\Services\AuditLogger;
use App\Services\LoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoanController extends Controller
{
    public function __construct(private readonly LoanService $loans) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', InstructorLoan::class);

        $query = InstructorLoan::query()
            ->with('instructor')
            ->when($request->filled('instructor_id'), fn ($q) => $q->where('instructor_id', $request->integer('instructor_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('loan_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('loan_date', '<=', $request->date('date_to')));

        return view('admin.loans.index', [
            'loans' => (clone $query)->orderByDesc('loan_date')->paginate(15)->withQueryString(),
            'instructors' => Instructor::orderBy('full_name')->get(['id', 'full_name']),
            'totals' => [
                'issued' => (float) (clone $query)->sum('amount'),
                'remaining' => (float) (clone $query)->sum('remaining_amount'),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', InstructorLoan::class);

        return view('admin.loans.form', [
            'loan' => new InstructorLoan(['loan_date' => now()->toDateString()]),
            'instructors' => Instructor::active()->orderBy('full_name')->get(),
        ]);
    }

    public function store(InstructorLoanRequest $request): RedirectResponse
    {
        $this->authorize('create', InstructorLoan::class);

        $loan = $this->loans->create($request->validated(), $request->user());

        return redirect()->route('admin.loans.show', $loan)->with('status', __('Loan recorded.'));
    }

    public function show(InstructorLoan $loan): View
    {
        $this->authorize('view', $loan);

        return view('admin.loans.show', [
            'loan' => $loan->load(['instructor', 'creator']),
            'payments' => $loan->payments()->with('creator')->orderByDesc('payment_date')->get(),
        ]);
    }

    public function edit(InstructorLoan $loan): View
    {
        $this->authorize('update', $loan);

        return view('admin.loans.form', [
            'loan' => $loan,
            'instructors' => Instructor::active()->orderBy('full_name')->get(),
        ]);
    }

    public function update(InstructorLoanRequest $request, InstructorLoan $loan): RedirectResponse
    {
        $this->authorize('update', $loan);

        $paid = (float) $loan->payments()->sum('amount');

        if ((float) $request->input('amount') < $paid) {
            return back()->withInput()->withErrors([
                'amount' => __('The loan cannot be lower than the :amount already repaid.', ['amount' => number_format($paid, 2)]),
            ]);
        }

        $original = $loan->getOriginal();
        $loan->update($request->validated());
        $loan->recalculate();
        AuditLogger::updated($loan, "Loan {$loan->loan_number} updated", $original);

        return redirect()->route('admin.loans.show', $loan)->with('status', __('Loan updated.'));
    }

    public function destroy(InstructorLoan $loan): RedirectResponse
    {
        $this->authorize('delete', $loan);

        if ($loan->payments()->exists()) {
            return back()->withErrors(['loan' => __('Remove the repayments on this loan first.')]);
        }

        $number = $loan->loan_number;
        $loan->delete();
        AuditLogger::log('loan.deleted', $loan, "Loan {$number} removed");

        return redirect()->route('admin.loans.index')->with('status', __('Loan removed.'));
    }

    public function storePayment(Request $request, InstructorLoan $loan): RedirectResponse
    {
        $this->authorize('recordPayment', $loan);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.max((float) $loan->remaining_amount, 0.01)],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(InstructorLoanPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->loans->recordPayment($loan, $data, $request->user());

        return back()->with('status', __('Repayment recorded.'));
    }
}
