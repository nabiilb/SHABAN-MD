<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleRequest;
use App\Models\Instructor;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Support\DocumentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class VehicleController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Vehicle::class);

        $vehicles = Vehicle::query()
            ->visibleTo($request->user())
            ->with('instructor')
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q
                    ->where('plate_number', 'like', $term)
                    ->orWhere('vehicle_number', 'like', $term)
                    ->orWhere('make', 'like', $term)
                    ->orWhere('model', 'like', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('instructor_id'), fn ($q) => $q->where('instructor_id', $request->integer('instructor_id')))
            ->orderBy('vehicle_number')
            ->paginate(15)
            ->withQueryString();

        return view('admin.vehicles.index', [
            'vehicles' => $vehicles,
            'instructors' => Instructor::active()->orderBy('full_name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Vehicle::class);

        return view('admin.vehicles.form', [
            'vehicle' => new Vehicle(['status' => 'available']),
            'instructors' => Instructor::active()->orderBy('full_name')->get(),
        ]);
    }

    public function store(VehicleRequest $request): RedirectResponse
    {
        $this->authorize('create', Vehicle::class);

        $vehicle = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data['vehicle_number'] = DocumentNumber::next(Vehicle::class, 'vehicle_number', 'VEH');

            $vehicle = Vehicle::create($data);
            AuditLogger::created($vehicle, "Vehicle {$vehicle->plate_number} added");

            return $vehicle;
        });

        return redirect()->route('admin.vehicles.show', $vehicle)->with('status', __('Vehicle added.'));
    }

    public function show(Vehicle $vehicle): View
    {
        $this->authorize('view', $vehicle);

        return view('admin.vehicles.show', [
            'vehicle' => $vehicle->load('instructor'),
            'expenses' => $vehicle->expenses()->with('category')->latest('expense_date')->limit(15)->get(),
            'fuelRecords' => $vehicle->fuelRecords()->latest('fuel_date')->limit(15)->get(),
            'lessonCount' => $vehicle->lessons()->count(),
            'totalExpenses' => (float) $vehicle->expenses()->sum('amount'),
        ]);
    }

    public function edit(Vehicle $vehicle): View
    {
        $this->authorize('update', $vehicle);

        return view('admin.vehicles.form', [
            'vehicle' => $vehicle,
            'instructors' => Instructor::active()->orderBy('full_name')->get(),
        ]);
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('update', $vehicle);

        $original = $vehicle->getOriginal();
        $vehicle->update($request->validated());
        AuditLogger::updated($vehicle, "Vehicle {$vehicle->plate_number} updated", $original);

        return redirect()->route('admin.vehicles.show', $vehicle)->with('status', __('Vehicle updated.'));
    }

    public function destroy(Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('delete', $vehicle);

        $plate = $vehicle->plate_number;
        $vehicle->update(['status' => 'inactive', 'instructor_id' => null]);
        $vehicle->delete();

        AuditLogger::log('vehicle.deleted', $vehicle, "Vehicle {$plate} removed");

        return redirect()->route('admin.vehicles.index')->with('status', __('Vehicle removed.'));
    }
}
