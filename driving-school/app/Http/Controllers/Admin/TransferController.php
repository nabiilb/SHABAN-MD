<?php

namespace App\Http\Controllers\Admin;

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

        $records = StudentTransfer::query()
            ->visibleTo($request->user())
            ->with(['student', 'fromInstructor', 'toInstructor', 'transferredBy'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('instructor_id'), function ($q) use ($request) {
                $id = $request->integer('instructor_id');
                $q->where(fn ($sub) => $sub->where('from_instructor_id', $id)->orWhere('to_instructor_id', $id));
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('transfer_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('transfer_date', '<=', $request->date('date_to')))
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.transfers.index', [
            'records' => $records,
            'students' => Student::orderBy('full_name')->get(['id', 'full_name']),
            'instructors' => Instructor::orderBy('full_name')->get(['id', 'full_name']),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', StudentTransfer::class);

        return view('admin.transfers.form', [
            'students' => Student::with('currentInstructor')->where('status', 'active')->orderBy('full_name')->get(),
            'instructors' => Instructor::active()->orderBy('full_name')->get(),
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
            ->route('admin.transfers.index')
            ->with('status', __(':student has been transferred to :instructor.', [
                'student' => $student->full_name,
                'instructor' => $target->full_name,
            ]));
    }
}
