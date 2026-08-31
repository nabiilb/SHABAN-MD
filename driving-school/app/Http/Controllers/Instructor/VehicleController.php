<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VehicleController extends Controller
{
    /** Only the vehicles assigned to the signed-in instructor. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Vehicle::class);

        return view('instructor.vehicles.index', [
            'vehicles' => Vehicle::query()
                ->visibleTo($request->user())
                ->orderBy('vehicle_number')
                ->paginate(15),
        ]);
    }

    public function show(Vehicle $vehicle): View
    {
        $this->authorize('view', $vehicle);

        return view('instructor.vehicles.show', [
            'vehicle' => $vehicle,
            'lessons' => $vehicle->lessons()
                ->where('instructor_id', request()->user()->instructorId())
                ->with('student')
                ->latest('lesson_date')
                ->limit(15)
                ->get(),
        ]);
    }
}
