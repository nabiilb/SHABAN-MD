<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StudentController extends Controller
{
    /** Only students CURRENTLY assigned to the signed-in instructor. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Student::class);

        $students = Student::query()
            ->visibleTo($request->user())
            ->withProgress()
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q
                    ->where('full_name', 'like', $term)
                    ->orWhere('student_number', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('full_name')
            ->paginate(15)
            ->withQueryString();

        return view('instructor.students.index', ['students' => $students]);
    }

    /**
     * A student belonging to another instructor returns 403 here — changing
     * the id in the URL cannot bypass the policy.
     */
    public function show(Student $student): View
    {
        $this->authorize('view', $student);

        return view('instructor.students.show', [
            'student' => $student->load('currentInstructor'),
            'attendance' => $student->attendance()->latest('attendance_date')->limit(20)->get(),
            'lessons' => $student->lessons()->with('lessonTopic')->latest('lesson_date')->limit(20)->get(),
            'assignments' => $student->assignments()->with('instructor')->orderByDesc('assigned_from')->get(),
        ]);
    }
}
