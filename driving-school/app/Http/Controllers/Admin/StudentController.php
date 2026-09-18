<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentRequest;
use App\Models\Instructor;
use App\Models\Student;
use App\Services\AuditLogger;
use App\Services\DashboardService;
use App\Services\StudentPaymentService;
use App\Services\StudentTransferService;
use App\Support\DocumentNumber;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StudentController extends Controller
{
    public function __construct(
        private readonly StudentTransferService $transfers,
        private readonly StudentPaymentService $payments,
        private readonly DashboardService $dashboard,
    ) {}

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

    /**
     * The students who owe the school money — the page behind the dashboard's
     * Unpaid Student Fees card.
     *
     * The list and the card's total are the same query asked twice, summed on
     * the dashboard and listed here, so the figure a manager clicks and the
     * rows they land on cannot disagree. Fully paid students are not rows with
     * 0.00 in them; they are not here at all.
     */
    public function unpaid(Request $request): View
    {
        $this->authorize('viewAny', Student::class);

        $query = $this->dashboard->unpaidStudentsQuery()
            ->with('currentInstructor')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($sub) => $sub
                    ->where('full_name', 'like', $term)
                    ->orWhere('student_number', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            });

        return view('admin.students.unpaid', [
            'students' => (clone $query)->orderByDesc('remaining_amount')->paginate(25)->withQueryString(),
            // The whole arrears figure, not just this page's worth, so it can
            // be checked against the dashboard card at a glance.
            'outstanding' => $this->dashboard->outstandingFees(),
            'filtered' => round((float) (clone $query)->sum(DB::raw('students.total_fee - coalesce(p.paid, 0)')), 2),
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

    /**
     * Registers a student and banks whatever they paid at the counter.
     *
     * The student, their first assignment and the payment are one transaction:
     * if any part of it fails, none of it happened. There is no window in which
     * a student exists with money recorded against them that the school did not
     * take, or in which cash is banked for a student who was never created.
     *
     * The payment is the income entry — see StudentPaymentService — so nothing
     * further is written for the accounts, and Amount Paid of 0 writes no
     * payment at all.
     *
     * A double-clicked form sends this twice. The phone rule refuses the second
     * one, and where both requests are in flight at once — neither committed
     * when the other validated — the unique index over `active_phone_key`
     * refuses it instead, and the 1062 is turned back into the same message the
     * admin would have seen anyway. One registration, one student, one payment.
     */
    public function store(StudentRequest $request): RedirectResponse
    {
        $this->authorize('create', Student::class);

        try {
            $student = DB::transaction(function () use ($request) {
                $data = $request->studentData();
                $data['student_number'] = DocumentNumber::next(Student::class, 'student_number', 'STD');

                if ($request->hasFile('profile_photo')) {
                    $data['profile_photo'] = $request->file('profile_photo')->store('students', 'public');
                }

                $student = Student::create($data);

                $this->transfers->assignInitial($student, $student->current_instructor_id, $request->user());

                if ($payment = $request->registrationPayment()) {
                    $this->payments->recordRegistrationPayment($student, $payment, $request->user());
                }

                AuditLogger::created($student, "Student {$student->full_name} registered");

                return $student;
            });
        } catch (QueryException $e) {
            // The guard index caught the race the validation rule could not
            // see. Nothing was written — the transaction took the student, the
            // assignment and the payment with it.
            if (! str_contains($e->getMessage(), 'students_active_phone_unique')) {
                throw $e;
            }

            $existing = Student::activeWithPhone($request->input('phone'));

            throw ValidationException::withMessages([
                'phone' => $existing
                    ? __('An active student with this phone number is already registered: :name (:number).', [
                        'name' => $existing->full_name,
                        'number' => $existing->student_number,
                    ])
                    : __('An active student with this phone number is already registered.'),
            ]);
        }

        $paid = $student->total_paid;

        return redirect()
            ->route('admin.students.show', $student)
            ->with('status', $paid > 0
                ? __('Student :name has been registered. :paid recorded as income; :balance still owing.', [
                    'name' => $student->full_name,
                    'paid' => number_format($paid, 2),
                    'balance' => number_format($student->balance, 2),
                ])
                : __('Student :name has been registered.', ['name' => $student->full_name]));
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
            // studentData(), not validated(): an edit never carries payment
            // fields, and changing the Total Fee moves what is owed without
            // touching a single payment or a penny of recorded income.
            $data = $request->studentData();

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
