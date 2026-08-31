<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LessonController extends Controller
{
    public function __invoke(Request $request): View
    {
        $lessons = Lesson::query()
            ->visibleTo($request->user())
            ->with(['instructor', 'lessonTopic', 'vehicle'])
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('lesson_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('lesson_date', '<=', $request->date('date_to')))
            ->orderByDesc('lesson_date')
            ->paginate(20)
            ->withQueryString();

        return view('student.lessons', ['lessons' => $lessons]);
    }
}
