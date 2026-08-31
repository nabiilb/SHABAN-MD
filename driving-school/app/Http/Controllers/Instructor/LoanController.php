<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\InstructorLoan;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "My Loan & Credit" — read only, and only the signed-in instructor's own
 * loans. No other instructor's balance is reachable from here.
 */
class LoanController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', InstructorLoan::class);

        $loans = InstructorLoan::query()
            ->visibleTo($request->user())
            ->with('payments')
            ->orderByDesc('loan_date')
            ->get();

        return view('instructor.loans.index', [
            'loans' => $loans,
            'totals' => [
                'total' => (float) $loans->where('status', '!=', 'cancelled')->sum('amount'),
                'paid' => (float) $loans->where('status', '!=', 'cancelled')->sum(fn ($loan) => $loan->paid_amount),
                'remaining' => (float) $loans->whereIn('status', ['outstanding', 'partially_paid'])->sum('remaining_amount'),
            ],
        ]);
    }

    public function show(InstructorLoan $loan): View
    {
        $this->authorize('view', $loan);

        return view('instructor.loans.show', [
            'loan' => $loan->load('payments'),
        ]);
    }
}
