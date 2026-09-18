<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\InstructorFuelRequest;
use App\Models\FuelRecord;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Services\FuelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * Fuel, read only, and only the instructor's own.
 *
 * The filtering is FuelRecord::visibleTo(), the same scope the admin list uses
 * — it simply returns everything for an admin — so there is one definition of
 * who may see a fill-up rather than two that can drift apart.
 */
class FuelController extends Controller
{
    public function __construct(private readonly FuelService $fuel) {}

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

    /** The Add Fuel form: only this instructor's vehicles are offered. */
    public function create(Request $request): View
    {
        $this->authorize('create', FuelRecord::class);

        return view('instructor.fuel.form', [
            'vehicles' => Vehicle::query()->visibleTo($request->user())->orderBy('vehicle_number')->get(),
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'defaults' => new FuelRecord([
                'fuel_date' => today()->toDateString(),
                'payment_method' => 'cash',
            ]),
            // Carried into the form and enforced by a unique index, so a
            // double click, a refresh or a retried POST cannot post twice.
            'submissionToken' => (string) Str::uuid(),
        ]);
    }

    /**
     * Records the fill-up through the same service the admin uses, as pending:
     * nothing reaches the company ledger until an admin approves it.
     */
    public function store(InstructorFuelRequest $request): RedirectResponse
    {
        $this->authorize('create', FuelRecord::class);

        try {
            $fuel = $this->fuel->record($request->fuelData(), $request->user(), requiresApproval: true);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['fuel' => $e->getMessage()]);
        }

        return redirect()->route('instructor.fuel.index')->with('status', __(
            'Fuel record :number submitted for approval.',
            ['number' => $fuel->fuel_number],
        ));
    }

    public function show(FuelRecord $fuel): View
    {
        $this->authorize('view', $fuel);

        return view('instructor.fuel.show', [
            'fuel' => $fuel->load(['vehicle', 'instructor', 'supplier', 'debt']),
        ]);
    }
}
