<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LessonRequest;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\LessonTopic;
use App\Models\Student;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LessonController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Lesson::class);

        $lessons = Lesson::query()
            ->visibleTo($request->user())
            ->with(['student', 'instructor', 'lessonTopic', 'vehicle'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('instructor_id'), fn ($q) => $q->where('instructor_id', $request->integer('instructor_id')))
            ->when($request->filled('lesson_topic_id'), fn ($q) => $q->where('lesson_topic_id', $request->integer('lesson_topic_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('lesson_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('lesson_date', '<=', $request->date('date_to')))
            ->orderByDesc('lesson_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.lessons.index', [
            'lessons' => $lessons,
            'students' => Student::orderBy('full_name')->get(['id', 'full_name']),
            'instructors' => Instructor::orderBy('full_name')->get(['id', 'full_name']),
            'topics' => LessonTopic::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Lesson::class);

        return view('admin.lessons.form', [
            'lesson' => new Lesson([
                'lesson_date' => now()->toDateString(),
                'duration_minutes' => 60,
                'status' => 'completed',
                'student_id' => $request->integer('student_id') ?: null,
            ]),
            'students' => Student::with('currentInstructor')->orderBy('full_name')->get(),
            'topics' => LessonTopic::where('is_active', true)->orderBy('sort_order')->get(),
            'vehicles' => Vehicle::orderBy('vehicle_number')->get(),
        ]);
    }

    public function store(LessonRequest $request): RedirectResponse
    {
        $this->authorize('create', Lesson::class);

        $student = Student::findOrFail($request->integer('student_id'));

        $lesson = Lesson::create([
            ...$request->validated(),
            'instructor_id' => $student->current_instructor_id ?? $request->user()->instructorId(),
            'recorded_by' => $request->user()->id,
        ]);

        AuditLogger::created($lesson, "Lesson recorded for {$student->full_name}");

        return redirect()->route('admin.lessons.index')->with('status', __('Lesson recorded.'));
    }

    public function show(Lesson $lesson): View
    {
        $this->authorize('view', $lesson);

        return view('admin.lessons.show', [
            'lesson' => $lesson->load(['student', 'instructor', 'vehicle', 'lessonTopic', 'recorder']),
        ]);
    }

    public function edit(Lesson $lesson): View
    {
        $this->authorize('update', $lesson);

        return view('admin.lessons.form', [
            'lesson' => $lesson,
            'students' => Student::with('currentInstructor')->orderBy('full_name')->get(),
            'topics' => LessonTopic::where('is_active', true)->orderBy('sort_order')->get(),
            'vehicles' => Vehicle::orderBy('vehicle_number')->get(),
        ]);
    }

    public function update(LessonRequest $request, Lesson $lesson): RedirectResponse
    {
        $this->authorize('update', $lesson);

        $original = $lesson->getOriginal();
        $lesson->update($request->validated());
        AuditLogger::updated($lesson, 'Lesson updated', $original);

        return redirect()->route('admin.lessons.index')->with('status', __('Lesson updated.'));
    }

    public function destroy(Lesson $lesson): RedirectResponse
    {
        $this->authorize('delete', $lesson);

        $lesson->delete();
        AuditLogger::log('lesson.deleted', $lesson, 'Lesson removed');

        return back()->with('status', __('Lesson removed.'));
    }
}
