<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentRequest;
use App\Models\Instructor;
use App\Models\Student;
use App\Services\AuditLogger;
use App\Services\StudentTransferService;
use App\Support\DocumentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StudentController extends Controller
{
    public function __construct(private readonly StudentTransferService $transfers) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Student::class);

        $students = Student::query()
            ->visibleTo($request->user())
            ->with('currentInstructor')
            ->withProgress()
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q
                    ->where('full_name', 'like', $term)
                    ->orWhere('student_number', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($request->filled('instructor_id'), fn ($q) => $q->where('current_instructor_id', $request->integer('instructor_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('full_name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.students.index', [
            'students' => $students,
            'instructors' => Instructor::active()->orderBy('full_name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Student::class);

        return view('admin.students.form', [
            'student' => new Student(['status' => 'active', 'required_training_days' => 24, 'start_date' => now()]),
            'instructors' => Instructor::active()->orderBy('full_name')->get(),
        ]);
    }

    public function store(StudentRequest $request): RedirectResponse
    {
        $this->authorize('create', Student::class);

        $student = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data['student_number'] = DocumentNumber::next(Student::class, 'student_number', 'STD');

            if ($request->hasFile('profile_photo')) {
                $data['profile_photo'] = $request->file('profile_photo')->store('students', 'public');
            }

            $student = Student::create($data);

            $this->transfers->assignInitial($student, $student->current_instructor_id, $request->user());

            AuditLogger::created($student, "Student {$student->full_name} registered");

            return $student;
        });

        return redirect()
            ->route('admin.students.show', $student)
            ->with('status', __('Student :name has been registered.', ['name' => $student->full_name]));
    }

    public function show(Student $student): View
    {
        $this->authorize('view', $student);

        return view('admin.students.show', [
            'student' => $student->load(['currentInstructor', 'assignments.instructor', 'transfers.fromInstructor', 'transfers.toInstructor']),
            'attendance' => $student->attendance()->with('instructor')->latest('attendance_date')->limit(15)->get(),
            'lessons' => $student->lessons()->with(['instructor', 'lessonTopic'])->latest('lesson_date')->limit(15)->get(),
            'payments' => $student->payments()->latest('payment_date')->get(),
        ]);
    }

    public function edit(Student $student): View
    {
        $this->authorize('update', $student);

        return view('admin.students.form', [
            'student' => $student,
            'instructors' => Instructor::active()->orderBy('full_name')->get(),
        ]);
    }

    public function update(StudentRequest $request, Student $student): RedirectResponse
    {
        $this->authorize('update', $student);

        DB::transaction(function () use ($request, $student) {
            $original = $student->getOriginal();
            $data = $request->validated();

            if ($request->hasFile('profile_photo')) {
                $data['profile_photo'] = $request->file('profile_photo')->store('students', 'public');
            }

            $previousInstructor = $student->current_instructor_id;
            $student->update($data);

            // Reassignment from the admin screen keeps the assignment history.
            if ($previousInstructor !== $student->current_instructor_id && $student->current_instructor_id) {
                $student->assignments()->where('is_current', true)->update([
                    'is_current' => false,
                    'assigned_to' => now()->toDateString(),
                ]);

                $this->transfers->assignInitial($student, $student->current_instructor_id, $request->user());
            }

            AuditLogger::updated($student, "Student {$student->full_name} updated", $original);
        });

        return redirect()
            ->route('admin.students.show', $student)
            ->with('status', __('Student updated.'));
    }

    public function destroy(Student $student): RedirectResponse
    {
        $this->authorize('delete', $student);

        $name = $student->full_name;
        $student->delete();

        AuditLogger::log('student.deleted', $student, "Student {$name} deactivated");

        return redirect()->route('admin.students.index')->with('status', __('Student removed.'));
    }
}
