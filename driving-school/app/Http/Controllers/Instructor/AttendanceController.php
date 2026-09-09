<?php

namespace App\Http\Controllers\Instructor;

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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly StudentProgressService $progress,
        private readonly StudentTransferService $transfers,
    ) {}

    /** Active instructors this instructor may hand a student over to. */
    private function transferTargets(Request $request): Collection
    {
        return Instructor::active()
            ->whereKeyNot($request->user()->instructorId())
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'instructor_number']);
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Attendance::class);

        $records = Attendance::query()
            ->visibleTo($request->user())
            ->with('student')
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('attendance_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('attendance_date', '<=', $request->date('date_to')))
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('instructor.attendance.index', [
            'records' => $records,
            'students' => Student::visibleTo($request->user())->orderBy('full_name')->get(),
            'transferTargets' => $this->transferTargets($request),
        ]);
    }

    /** The check-in screen: search my students, pick a status, save. */
    public function create(Request $request): View
    {
        $this->authorize('create', Attendance::class);

        $students = Student::query()
            ->visibleTo($request->user())
            ->where('status', 'active')
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q->where('full_name', 'like', $term)->orWhere('student_number', 'like', $term));
            })
            ->withProgress()
            ->orderBy('full_name')
            ->get();

        $checkedInIds = Attendance::query()
            ->visibleTo($request->user())
            ->whereDate('attendance_date', today())
            ->pluck('student_id')
            ->all();

        return view('instructor.attendance.checkin', [
            'students' => $students,
            'checkedInIds' => $checkedInIds,
            'transferTargets' => $this->transferTargets($request),
            'attendance' => new Attendance([
                'attendance_date' => today()->toDateString(),
                'check_in_time' => now()->format('H:i'),
                'status' => 'present',
            ]),
        ]);
    }

    public function store(AttendanceRequest $request): RedirectResponse
    {
        // AttendanceRequest::authorize() has already proven the student is
        // assigned to this instructor.
        $attendance = DB::transaction(function () use ($request) {
            $student = Student::findOrFail($request->integer('student_id'));

            $attendance = Attendance::create([
                ...$request->validated(),
                // From the authenticated user, never from the request body.
                'instructor_id' => $request->user()->instructorId(),
                'recorded_by' => $request->user()->id,
            ]);

            $this->progress->recalculate($student);
            AuditLogger::created($attendance, "Checked in {$student->full_name}");

            return $attendance;
        });

        return redirect()
            ->route('instructor.attendance.create')
            ->with('status', __(':name has been checked in.', ['name' => $attendance->student->full_name]));
    }

    public function edit(Attendance $attendance): View
    {
        $this->authorize('update', $attendance);

        return view('instructor.attendance.form', [
            'attendance' => $attendance,
            'students' => Student::visibleTo(request()->user())->orderBy('full_name')->get(),
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

        return redirect()->route('instructor.attendance.index')->with('status', __('Attendance updated.'));
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
     * Hands a student, and today's attendance for that student, to another
     * instructor.
     *
     * The date is fixed to today inside this action, so an earlier day can
     * never be rewritten from the attendance screen. The request has already
     * proven the student is currently assigned to the acting instructor.
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
