<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(Request $request): View
    {
        $student = $request->user()->student;

        return view('student.dashboard', [
            'student' => $student->load('currentInstructor'),
            'metrics' => $this->dashboard->studentMetrics($student),
            'attendance' => $student->attendance()->latest('attendance_date')->limit(10)->get(),
            'lessons' => $student->lessons()->with('lessonTopic')->latest('lesson_date')->limit(10)->get(),
        ]);
    }
}
