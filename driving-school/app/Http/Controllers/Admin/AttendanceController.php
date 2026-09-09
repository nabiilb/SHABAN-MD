<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceRequest;
use App\Http\Requests\AttendanceTransferRequest;
use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Student;
use App\Services\AuditLogger;
use App\Services\StudentProgressService;
use App\Services\StudentTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly StudentProgressService $progress,
        private readonly StudentTransferService $transfers,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Attendance::class);

        $records = Attendance::query()
            ->visibleTo($request->user())
            ->with(['student', 'instructor'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('instructor_id'), fn ($q) => $q->where('instructor_id', $request->integer('instructor_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('attendance_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('attendance_date', '<=', $request->date('date_to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->whereHas('student', fn ($q) => $q
                    ->where('full_name', 'like', $term)
                    ->orWhere('student_number', 'like', $term));
            })
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.attendance.index', [
            'records' => $records,
            'students' => Student::orderBy('full_name')->get(['id', 'full_name', 'student_number']),
            'instructors' => Instructor::orderBy('full_name')->get(['id', 'full_name']),
            'transferTargets' => Instructor::active()->orderBy('full_name')->get(['id', 'full_name', 'instructor_number']),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Attendance::class);

        return view('admin.attendance.form', [
            'attendance' => new Attendance([
                'attendance_date' => now()->toDateString(),
                'status' => 'present',
                'check_in_time' => now()->format('H:i'),
                'student_id' => $request->integer('student_id') ?: null,
            ]),
            'students' => Student::with('currentInstructor')->orderBy('full_name')->get(),
        ]);
    }

    public function store(AttendanceRequest $request): RedirectResponse
    {
        $this->authorize('create', Attendance::class);

        $attendance = DB::transaction(function () use ($request) {
            $student = Student::findOrFail($request->integer('student_id'));

            $attendance = Attendance::create([
                ...$request->validated(),
                // Never taken from the payload: derived from the student's
                // current instructor (or the acting instructor).
                'instructor_id' => $student->current_instructor_id ?? $request->user()->instructorId(),
                'recorded_by' => $request->user()->id,
            ]);

            $this->progress->recalculate($student);
            AuditLogger::created($attendance, "Attendance recorded for {$student->full_name}");

            return $attendance;
        });

        return redirect()->route('admin.attendance.index')->with('status', __('Attendance recorded.'));
    }

    public function show(Attendance $attendance): View
    {
        $this->authorize('view', $attendance);

        return view('admin.attendance.show', ['attendance' => $attendance->load(['student', 'instructor', 'recorder'])]);
    }

    public function edit(Attendance $attendance): View
    {
        $this->authorize('update', $attendance);

        return view('admin.attendance.form', [
            'attendance' => $attendance,
            'students' => Student::with('currentInstructor')->orderBy('full_name')->get(),
        ]);
    }

    public function update(AttendanceRequest $request, Attendance $attendance): RedirectResponse
    {
        $this->authorize('update', $attendance);

        DB::transaction(function () use ($request, $attendance) {
            $original = $attendance->getOriginal();
            $attendance->update($request->validated());

            $this->progress->recalculate($attendance->student);
            AuditLogger::updated($attendance, 'Attendance updated', $original);
        });

        return redirect()->route('admin.attendance.index')->with('status', __('Attendance updated.'));
    }

    public function destroy(Attendance $attendance): RedirectResponse
    {
        $this->authorize('delete', $attendance);

        DB::transaction(function () use ($attendance) {
            $student = $attendance->student;
            $attendance->delete();
            $this->progress->recalculate($student);

            AuditLogger::log('attendance.deleted', $attendance, "Attendance removed for {$student->full_name}");
        });

        return back()->with('status', __('Attendance removed.'));
    }

    /**
     * Same-day transfer from the attendance screen. An admin may move any
     * student, but still only today's attendance moves with them.
     */
    public function transfer(AttendanceTransferRequest $request): RedirectResponse
    {
        $student = Student::findOrFail($request->integer('student_id'));
        $target = Instructor::findOrFail($request->integer('to_instructor_id'));

        $this->transfers->transfer(
            $student,
            $target,
            $request->transferReason(),
            $request->user(),
            today(),
            $request->input('notes'),
        );

        return back()->with('status', __('Student successfully transferred for today\'s attendance.'));
    }
}
