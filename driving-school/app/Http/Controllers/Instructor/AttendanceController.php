<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceRequest;
use App\Http\Requests\AttendanceTransferRequest;
use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\LessonTopic;
use App\Models\Student;
use App\Models\Vehicle;
use App\Services\AttendanceTransferService;
use App\Services\AuditLogger;
use App\Services\DailyLessonService;
use App\Services\StudentProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly StudentProgressService $progress,
        private readonly AttendanceTransferService $transfers,
        private readonly DailyLessonService $lessons,
    ) {}

    /** Lesson types and vehicles offered alongside the day's attendance. */
    private function lessonOptions(Request $request): array
    {
        return [
            'topics' => LessonTopic::where('is_active', true)->orderBy('sort_order')->get(),
            'vehicles' => Vehicle::visibleTo($request->user())->orderBy('vehicle_number')->get(),
        ];
    }

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
            ->with(['student', 'lesson.lessonTopic'])
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
            ->ownedByInstructorOn($request->user()->instructorId(), today())
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
            ...$this->lessonOptions($request),
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
                ...$request->safe()->only(['student_id', 'attendance_date', 'check_in_time', 'status', 'notes']),
                // From the authenticated user, never from the request body.
                'instructor_id' => $request->user()->instructorId(),
                'recorded_by' => $request->user()->id,
            ]);

            // The day's lesson and rating belong to this attendance record.
            $this->lessons->sync($attendance, $request->lessonData(), $request->user());

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
            'attendance' => $attendance->load('lesson'),
            'students' => Student::ownedByInstructorOn(
                request()->user()->instructorId(),
                $attendance->attendance_date,
            )->orderBy('full_name')->get(),
            ...$this->lessonOptions(request()),
        ]);
    }

    public function update(AttendanceRequest $request, Attendance $attendance): RedirectResponse
    {
        $this->authorize('update', $attendance);

        DB::transaction(function () use ($request, $attendance) {
            $original = $attendance->getOriginal();
            $attendance->update($request->safe()->only([
                'student_id', 'attendance_date', 'check_in_time', 'status', 'notes',
            ]));

            // Updates the SAME lesson row rather than adding another.
            $this->lessons->sync($attendance->refresh(), $request->lessonData(), $request->user());

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
            $this->lessons->detach($attendance);
            $attendance->delete();
            $this->progress->recalculate($student);
            AuditLogger::log('attendance.deleted', $attendance, "Attendance removed for {$student->full_name}");
        });

        return back()->with('status', __('Attendance removed.'));
    }

    /**
     * Hands today's attendance for a student — and the lesson taught on it —
     * to another instructor.
     *
     * The date is fixed to today inside this action, so an earlier day can
     * never be rewritten from the attendance screen. The student's permanent
     * instructor is untouched, so tomorrow they are back on their usual list.
     */
    public function transfer(AttendanceTransferRequest $request): RedirectResponse
    {
        $student = Student::findOrFail($request->integer('student_id'));
        $target = Instructor::findOrFail($request->integer('to_instructor_id'));

        $this->transfers->transfer(
            $student,
            $target,
            today(),
            $request->user(),
            $request->transferReason(),
            $request->input('notes'),
        );

        return back()->with('status', __('Student successfully transferred for today\'s attendance.'));
    }
}
