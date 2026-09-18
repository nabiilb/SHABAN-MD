<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    /** Read only, and only this student's own records. */
    public function __invoke(Request $request): View
    {
        $records = Attendance::query()
            ->visibleTo($request->user())
            ->with('instructor')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('attendance_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('attendance_date', '<=', $request->date('date_to')))
            ->orderByDesc('attendance_date')
            ->paginate(20)
            ->withQueryString();

        return view('student.attendance', ['records' => $records]);
    }
}
