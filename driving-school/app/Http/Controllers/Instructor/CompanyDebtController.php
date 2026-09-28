<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\CompanyDebt;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Company debts, read only, and only the ones that reach this instructor —
 * a debt against a vehicle assigned to them, or fuel they took on credit.
 *
 * Nothing here raises, edits, pays or cancels a debt: that is the company's
 * ledger and stays with the admin.
 */
class CompanyDebtController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', CompanyDebt::class);

        $query = CompanyDebt::query()
            ->visibleTo($request->user())
            ->with(['supplier', 'vehicle'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return view('instructor.company-debts.index', [
            'debts' => (clone $query)->orderByDesc('debt_date')->paginate(20)->withQueryString(),
            'statuses' => CompanyDebt::STATUSES,
            'totals' => [
                'original' => (float) (clone $query)->sum('original_amount'),
                'remaining' => (float) (clone $query)->sum('remaining_amount'),
            ],
        ]);
    }

    public function show(CompanyDebt $companyDebt): View
    {
        $this->authorize('view', $companyDebt);

        return view('instructor.company-debts.show', [
            'debt' => $companyDebt->load(['supplier', 'vehicle', 'category', 'payments', 'fuelRecords.vehicle']),
        ]);
    }
}
