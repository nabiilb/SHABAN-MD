<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyDebt;
use App\Models\CompanyExpense;
use App\Models\Student;
use App\Services\DashboardService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(): View
    {
        $metrics = $this->dashboard->adminMetrics();

        return view('admin.dashboard', [
            'metrics' => $metrics,
            'attendanceTrend' => $this->dashboard->attendanceTrend(14),
            'incomeTrend' => $this->dashboard->incomeTrend(6),
            'students' => Student::query()
                ->with('currentInstructor')
                ->withProgress()
                ->latest('start_date')
                ->limit(8)
                ->get(),
            'nearCompletion' => $this->dashboard->nearCompletionQuery()
                ->with('currentInstructor')
                ->withProgress()
                ->limit(6)
                ->get(),
            'recentExpenses' => CompanyExpense::query()
                ->with(['category', 'supplier'])
                ->latest('expense_date')
                ->limit(6)
                ->get(),
            'outstandingDebts' => CompanyDebt::query()
                ->outstanding()
                ->with('supplier')
                ->orderBy('due_date')
                ->limit(6)
                ->get(),
        ]);
    }
}
