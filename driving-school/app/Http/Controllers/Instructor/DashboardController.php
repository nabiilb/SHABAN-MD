<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Vehicle;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "My Dashboard" — a completely separate screen from the admin dashboard.
 * Every query below is bound to the authenticated instructor; no company
 * income, expense, debt or profit figure is ever loaded here.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(Request $request): View
    {
        $instructor = $request->user()->instructor;

        return view('instructor.dashboard', [
            'instructor' => $instructor,
            'metrics' => $this->dashboard->instructorMetrics($instructor),
            'attendanceTrend' => $this->dashboard->attendanceTrend(14, $instructor->id),
            'students' => Student::query()
                ->visibleTo($request->user())
                ->withProgress()
                ->orderBy('full_name')
                ->get(),
            'todaysAttendance' => Attendance::query()
                ->visibleTo($request->user())
                ->with('student')
                ->whereDate('attendance_date', today())
                ->orderByDesc('id')
                ->get(),
            'recentLessons' => Lesson::query()
                ->visibleTo($request->user())
                ->with(['student', 'lessonTopic'])
                ->latest('lesson_date')
                ->limit(8)
                ->get(),
            'vehicles' => Vehicle::query()
                ->visibleTo($request->user())
                ->orderBy('vehicle_number')
                ->get(),
            'loans' => $instructor->loans()
                ->whereIn('status', ['outstanding', 'partially_paid'])
                ->get(),
        ]);
    }
}
