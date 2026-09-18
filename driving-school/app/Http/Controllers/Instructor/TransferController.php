<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentTransferRequest;
use App\Models\Instructor;
use App\Models\Student;
use App\Models\StudentTransfer;
use App\Services\StudentTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransferController extends Controller
{
    public function __construct(private readonly StudentTransferService $transfers) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', StudentTransfer::class);

        return view('instructor.transfers.index', [
            'records' => StudentTransfer::query()
                ->visibleTo($request->user())
                ->with(['student', 'fromInstructor', 'toInstructor'])
                ->orderByDesc('transfer_date')
                ->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', StudentTransfer::class);

        return view('instructor.transfers.form', [
            // Only my own students can even appear in the picker — and the
            // policy re-checks it on submit.
            'students' => Student::visibleTo($request->user())->where('status', 'active')->orderBy('full_name')->get(),
            'instructors' => Instructor::active()
                ->whereKeyNot($request->user()->instructorId())
                ->orderBy('full_name')
                ->get(),
            'selectedStudent' => $request->integer('student_id') ?: null,
        ]);
    }

    public function store(StudentTransferRequest $request): RedirectResponse
    {
        $student = Student::findOrFail($request->integer('student_id'));
        $target = Instructor::findOrFail($request->integer('to_instructor_id'));

        $this->transfers->transfer(
            $student,
            $target,
            $request->string('reason'),
            $request->user(),
            $request->date('transfer_date'),
            $request->input('notes'),
        );

        return redirect()
            ->route('instructor.transfers.index')
            ->with('status', __(':student has been transferred to :instructor.', [
                'student' => $student->full_name,
                'instructor' => $target->full_name,
            ]));
    }
}
