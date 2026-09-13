<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentPaymentRequest;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Services\AuditLogger;
use App\Support\DocumentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StudentPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', StudentPayment::class);

        $query = StudentPayment::query()
            ->visibleTo($request->user())
            ->with('student')
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->string('payment_method')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('payment_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('payment_date', '<=', $request->date('date_to')));

        return view('admin.student-payments.index', [
            'payments' => (clone $query)->orderByDesc('payment_date')->paginate(20)->withQueryString(),
            'students' => Student::orderBy('full_name')->get(['id', 'full_name', 'student_number']),
            'total' => (float) (clone $query)->sum('amount'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', StudentPayment::class);

        return view('admin.student-payments.form', [
            'payment' => new StudentPayment([
                'payment_date' => now()->toDateString(),
                'payment_method' => 'cash',
                'student_id' => $request->integer('student_id') ?: null,
            ]),
            'students' => Student::orderBy('full_name')->get(),
        ]);
    }

    public function store(StudentPaymentRequest $request): RedirectResponse
    {
        $this->authorize('create', StudentPayment::class);

        DB::transaction(function () use ($request) {
            $payment = StudentPayment::create([
                ...$request->validated(),
                'payment_number' => DocumentNumber::next(StudentPayment::class, 'payment_number', 'PAY'),
                'created_by' => $request->user()->id,
            ]);

            AuditLogger::created($payment, "Student payment {$payment->payment_number} of {$payment->amount}");
        });

        return redirect()->route('admin.student-payments.index')->with('status', __('Payment recorded.'));
    }

    public function show(StudentPayment $payment): View
    {
        $this->authorize('view', $payment);

        return view('admin.student-payments.show', ['payment' => $payment->load(['student', 'creator'])]);
    }

    public function edit(StudentPayment $payment): View
    {
        $this->authorize('update', $payment);

        return view('admin.student-payments.form', [
            'payment' => $payment,
            'students' => Student::orderBy('full_name')->get(),
        ]);
    }

    public function update(StudentPaymentRequest $request, StudentPayment $payment): RedirectResponse
    {
        $this->authorize('update', $payment);

        $original = $payment->getOriginal();
        $payment->update($request->validated());
        AuditLogger::updated($payment, "Student payment {$payment->payment_number} updated", $original);

        return redirect()->route('admin.student-payments.index')->with('status', __('Payment updated.'));
    }

    public function destroy(StudentPayment $payment): RedirectResponse
    {
        $this->authorize('delete', $payment);

        $number = $payment->payment_number;
        $payment->delete();
        AuditLogger::log('studentpayment.deleted', $payment, "Student payment {$number} removed");

        return back()->with('status', __('Payment removed.'));
    }
}
