<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\FuelRecord;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fuel, read only, and only the instructor's own.
 *
 * The filtering is FuelRecord::visibleTo(), the same scope the admin list uses
 * — it simply returns everything for an admin — so there is one definition of
 * who may see a fill-up rather than two that can drift apart.
 */
class FuelController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', FuelRecord::class);

        $query = FuelRecord::query()
            ->visibleTo($request->user())
            ->with(['vehicle', 'supplier'])
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('fuel_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('fuel_date', '<=', $request->date('date_to')));

        return view('instructor.fuel.index', [
            'records' => (clone $query)->orderByDesc('fuel_date')->paginate(20)->withQueryString(),
            'vehicles' => Vehicle::query()->visibleTo($request->user())->orderBy('vehicle_number')->get(),
            'totals' => [
                'amount' => (float) (clone $query)->sum('amount'),
                'liters' => (float) (clone $query)->sum('liters'),
            ],
        ]);
    }

    public function show(FuelRecord $fuel): View
    {
        $this->authorize('view', $fuel);

        return view('instructor.fuel.show', [
            'fuel' => $fuel->load(['vehicle', 'instructor', 'supplier', 'debt']),
        ]);
    }
}
