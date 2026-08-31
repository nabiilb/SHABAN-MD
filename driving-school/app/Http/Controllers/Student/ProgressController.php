<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgressController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(Request $request): View
    {
        $student = $request->user()->student;

        return view('student.progress', [
            'student' => $student->load('currentInstructor'),
            'metrics' => $this->dashboard->studentMetrics($student),
            'attendanceTrend' => $this->dashboard->attendanceTrend(21, null, $student->id),
            'topicBreakdown' => $student->lessons()
                ->selectRaw('lesson_topic_id, count(*) as total')
                ->with('lessonTopic')
                ->groupBy('lesson_topic_id')
                ->get(),
        ]);
    }
}
