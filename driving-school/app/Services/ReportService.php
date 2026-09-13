<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CompanyDebt;
use App\Models\CompanyExpense;
use App\Models\DebtPayment;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\StudentTransfer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Builds every report from live queries. Each report returns its column
 * definitions plus the rows, so one Blade template renders the screen, the
 * CSV export and the PDF.
 */
class ReportService
{
    /** Reports an admin may run. */
    public const ADMIN_REPORTS = [
        'students', 'attendance', 'instructors', 'vehicles', 'expenses',
        'debts', 'debt-payments', 'supplier-statement', 'student-progress', 'transfers',
    ];

    /** The only reports an instructor may run — all scoped to himself. */
    public const INSTRUCTOR_REPORTS = ['my-students', 'my-attendance', 'my-lessons', 'my-progress'];

    public function titles(): array
    {
        return [
            'students' => __('Student Report'),
            'attendance' => __('Attendance Report'),
            'instructors' => __('Instructor Report'),
            'vehicles' => __('Vehicle Report'),
            'expenses' => __('Expense Report'),
            'debts' => __('Debt Report'),
            'debt-payments' => __('Debt Payment Report'),
            'supplier-statement' => __('Supplier Statement'),
            'student-progress' => __('Student Progress Report'),
            'transfers' => __('Transfer Report'),
            'my-students' => __('My Students'),
            'my-attendance' => __('My Attendance'),
            'my-lessons' => __('My Lessons'),
            'my-progress' => __('My Progress'),
        ];
    }

    public function build(string $report, array $filters, User $user): array
    {
        return match ($report) {
            'students', 'my-students' => $this->students($filters, $user),
            'attendance', 'my-attendance' => $this->attendance($filters, $user),
            'lessons', 'my-lessons' => $this->lessons($filters, $user),
            'student-progress', 'my-progress' => $this->progress($filters, $user),
            'instructors' => $this->instructors($filters),
            'vehicles' => $this->vehicles($filters),
            'expenses' => $this->expenses($filters),
            'debts' => $this->debts($filters),
            'debt-payments' => $this->debtPayments($filters),
            'supplier-statement' => $this->supplierStatement($filters),
            'transfers' => $this->transfers($filters, $user),
            default => ['columns' => [], 'rows' => collect(), 'summary' => []],
        };
    }

    /* ---------------------------------------------------------------- */

    protected function students(array $f, User $user): array
    {
        $rows = Student::query()
            ->visibleTo($user)
            ->with('currentInstructor')
            ->withProgress()
            ->when($f['instructor_id'] ?? null, fn ($q, $v) => $q->where('current_instructor_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('start_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('start_date', '<=', $v))
            ->orderBy('full_name')
            ->get()
            ->map(fn (Student $s) => [
                __('Number') => $s->student_number,
                __('Student') => $s->full_name,
                __('Phone') => $s->phone,
                __('Instructor') => $s->currentInstructor?->full_name ?? '—',
                __('Start Date') => $s->start_date?->format('d/m/Y'),
                __('Required') => $s->required_training_days,
                __('Completed') => $s->completed_days,
                __('Remaining') => $s->remaining_days,
                __('Progress') => $s->progress_percentage.'%',
                __('Status') => __(ucfirst($s->status)),
            ]);

        return [
            'columns' => $rows->first() ? array_keys($rows->first()) : [],
            'rows' => $rows,
            'summary' => [__('Total students') => $rows->count()],
        ];
    }

    protected function attendance(array $f, User $user): array
    {
        $query = Attendance::query()
            ->visibleTo($user)
            ->with(['student', 'instructor'])
            ->when($f['student_id'] ?? null, fn ($q, $v) => $q->where('student_id', $v))
            ->when($f['instructor_id'] ?? null, fn ($q, $v) => $q->where('instructor_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('attendance_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('attendance_date', '<=', $v))
            ->orderByDesc('attendance_date');

        $rows = $query->get()->map(fn (Attendance $a) => [
            __('Date') => $a->attendance_date?->format('d/m/Y'),
            __('Student') => $a->student?->full_name,
            __('Instructor') => $a->instructor?->full_name,
            __('Check-in') => $a->check_in_time ? substr($a->check_in_time, 0, 5) : '—',
            __('Status') => __(ucfirst($a->status)),
            __('Notes') => $a->notes,
        ]);

        return [
            'columns' => [__('Date'), __('Student'), __('Instructor'), __('Check-in'), __('Status'), __('Notes')],
            'rows' => $rows,
            'summary' => [
                __('Records') => $rows->count(),
                __('Present') => $rows->where(__('Status'), __('Present'))->count(),
                __('Absent') => $rows->where(__('Status'), __('Absent'))->count(),
            ],
        ];
    }

    protected function lessons(array $f, User $user): array
    {
        $rows = Lesson::query()
            ->visibleTo($user)
            ->with(['student', 'instructor', 'lessonTopic', 'vehicle'])
            ->when($f['student_id'] ?? null, fn ($q, $v) => $q->where('student_id', $v))
            ->when($f['instructor_id'] ?? null, fn ($q, $v) => $q->where('instructor_id', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('lesson_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('lesson_date', '<=', $v))
            ->orderByDesc('lesson_date')
            ->get()
            ->map(fn (Lesson $l) => [
                __('Date') => $l->lesson_date?->format('d/m/Y'),
                __('Student') => $l->student?->full_name,
                __('Instructor') => $l->instructor?->full_name,
                __('Lesson Type') => $l->lessonTopic?->display_name,
                __('Topic') => $l->topic,
                __('Vehicle') => $l->vehicle?->plate_number ?? '—',
                __('Duration') => $l->duration_minutes.' '.__('min'),
                __('Performance') => $l->performance ? __(ucwords(str_replace('_', ' ', $l->performance))) : '—',
                __('Status') => __(ucfirst($l->status)),
            ]);

        return [
            'columns' => $rows->first() ? array_keys($rows->first()) : [],
            'rows' => $rows,
            'summary' => [__('Lessons') => $rows->count()],
        ];
    }

    protected function progress(array $f, User $user): array
    {
        $rows = Student::query()
            ->visibleTo($user)
            ->with('currentInstructor')
            ->withProgress()
            ->when($f['instructor_id'] ?? null, fn ($q, $v) => $q->where('current_instructor_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (Student $s) => [
                __('Student') => $s->full_name,
                __('Instructor') => $s->currentInstructor?->full_name ?? '—',
                __('Required') => $s->required_training_days,
                __('Completed') => $s->completed_days,
                __('Remaining') => $s->remaining_days,
                __('Progress') => $s->progress_percentage.'%',
                __('Completion Date') => $s->completion_date?->format('d/m/Y') ?? '—',
                __('Status') => __(ucfirst($s->status)),
            ]);

        return [
            'columns' => $rows->first() ? array_keys($rows->first()) : [],
            'rows' => $rows,
            'summary' => [
                __('Students') => $rows->count(),
                __('Completed') => $rows->where(__('Status'), __('Completed'))->count(),
            ],
        ];
    }

    protected function instructors(array $f): array
    {
        $rows = Instructor::query()
            ->withCount(['students', 'vehicles', 'attendance', 'lessons'])
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['instructor_id'] ?? null, fn ($q, $v) => $q->whereKey($v))
            ->orderBy('full_name')
            ->get()
            ->map(fn (Instructor $i) => [
                __('Number') => $i->instructor_number,
                __('Instructor') => $i->full_name,
                __('Phone') => $i->phone,
                __('Joining Date') => $i->joining_date?->format('d/m/Y'),
                __('Students') => $i->students_count,
                __('Vehicles') => $i->vehicles_count,
                __('Attendance') => $i->attendance_count,
                __('Lessons') => $i->lessons_count,
                __('Outstanding Loan') => number_format($i->outstanding_loan, 2),
                __('Status') => __(ucfirst($i->status)),
            ]);

        return ['columns' => $rows->first() ? array_keys($rows->first()) : [], 'rows' => $rows, 'summary' => [__('Instructors') => $rows->count()]];
    }

    protected function vehicles(array $f): array
    {
        $rows = Vehicle::query()
            ->with('instructor')
            ->withSum('expenses as expenses_total', 'amount')
            ->withSum('fuelRecords as fuel_total', 'amount')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['vehicle_id'] ?? null, fn ($q, $v) => $q->whereKey($v))
            ->when($f['instructor_id'] ?? null, fn ($q, $v) => $q->where('instructor_id', $v))
            ->orderBy('vehicle_number')
            ->get()
            ->map(fn (Vehicle $v) => [
                __('Number') => $v->vehicle_number,
                __('Plate') => $v->plate_number,
                __('Vehicle') => $v->label,
                __('Year') => $v->year ?? '—',
                __('Mileage') => number_format($v->mileage),
                __('Instructor') => $v->instructor?->full_name ?? '—',
                __('Expenses') => number_format((float) $v->expenses_total, 2),
                __('Fuel') => number_format((float) $v->fuel_total, 2),
                __('Status') => __(ucwords(str_replace('_', ' ', $v->status))),
            ]);

        return [
            'columns' => $rows->first() ? array_keys($rows->first()) : [],
            'rows' => $rows,
            'summary' => [__('Vehicles') => $rows->count()],
        ];
    }

    protected function expenses(array $f): array
    {
        $query = CompanyExpense::query()
            ->with(['category', 'vehicle', 'supplier'])
            ->when($f['expense_category_id'] ?? null, fn ($q, $v) => $q->where('expense_category_id', $v))
            ->when($f['vehicle_id'] ?? null, fn ($q, $v) => $q->where('vehicle_id', $v))
            ->when($f['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->orderByDesc('expense_date');

        $total = (float) (clone $query)->sum('amount');

        $rows = $query->get()->map(fn (CompanyExpense $e) => [
            __('Number') => $e->expense_number,
            __('Date') => $e->expense_date?->format('d/m/Y'),
            __('Category') => $e->category?->display_name,
            __('Description') => $e->description,
            __('Vehicle') => $e->vehicle?->plate_number ?? '—',
            __('Supplier') => $e->supplier?->name ?? '—',
            __('Method') => __(ucwords(str_replace('_', ' ', $e->payment_method))),
            __('Amount') => number_format((float) $e->amount, 2),
        ]);

        return [
            'columns' => $rows->first() ? array_keys($rows->first()) : [],
            'rows' => $rows,
            'summary' => [__('Records') => $rows->count(), __('Total expenses') => number_format($total, 2)],
        ];
    }

    protected function debts(array $f): array
    {
        $query = CompanyDebt::query()
            ->with('supplier')
            ->when($f['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('debt_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('debt_date', '<=', $v))
            ->orderByDesc('debt_date');

        $original = (float) (clone $query)->sum('original_amount');
        $remaining = (float) (clone $query)->sum('remaining_amount');

        $rows = $query->get()->map(fn (CompanyDebt $d) => [
            __('Number') => $d->debt_number,
            __('Supplier') => $d->supplier?->name,
            __('Description') => $d->description,
            __('Debt Date') => $d->debt_date?->format('d/m/Y'),
            __('Due Date') => $d->due_date?->format('d/m/Y') ?? '—',
            __('Original') => number_format((float) $d->original_amount, 2),
            __('Paid') => number_format($d->paid_amount, 2),
            __('Remaining') => number_format((float) $d->remaining_amount, 2),
            __('Status') => __(ucwords(str_replace('_', ' ', $d->status))),
        ]);

        return [
            'columns' => $rows->first() ? array_keys($rows->first()) : [],
            'rows' => $rows,
            'summary' => [
                __('Original total') => number_format($original, 2),
                __('Paid total') => number_format($original - $remaining, 2),
                __('Outstanding') => number_format($remaining, 2),
            ],
        ];
    }

    protected function debtPayments(array $f): array
    {
        $query = DebtPayment::query()
            ->with(['companyDebt.supplier', 'expense'])
            ->when($f['supplier_id'] ?? null, fn ($q, $v) => $q->whereHas('companyDebt', fn ($d) => $d->where('supplier_id', $v)))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '<=', $v))
            ->orderByDesc('payment_date');

        $total = (float) (clone $query)->sum('amount');

        $rows = $query->get()->map(fn (DebtPayment $p) => [
            __('Date') => $p->payment_date?->format('d/m/Y'),
            __('Debt') => $p->companyDebt?->debt_number,
            __('Supplier') => $p->companyDebt?->supplier?->name,
            __('Method') => __(ucwords(str_replace('_', ' ', $p->payment_method))),
            __('Reference') => $p->reference ?? '—',
            __('Expense') => $p->expense?->expense_number ?? '—',
            __('Amount') => number_format((float) $p->amount, 2),
        ]);

        return [
            'columns' => $rows->first() ? array_keys($rows->first()) : [],
            'rows' => $rows,
            'summary' => [__('Payments') => $rows->count(), __('Total paid') => number_format($total, 2)],
        ];
    }

    protected function supplierStatement(array $f): array
    {
        $supplierId = $f['supplier_id'] ?? null;

        if (! $supplierId) {
            return ['columns' => [], 'rows' => collect(), 'summary' => [__('Select a supplier') => '—']];
        }

        $debts = CompanyDebt::where('supplier_id', $supplierId)
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('debt_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('debt_date', '<=', $v))
            ->get()
            ->map(fn (CompanyDebt $d) => [
                'date' => $d->debt_date,
                __('Date') => $d->debt_date?->format('d/m/Y'),
                __('Reference') => $d->debt_number,
                __('Type') => __('Debt'),
                __('Description') => $d->description,
                __('Debit') => number_format((float) $d->original_amount, 2),
                __('Credit') => '—',
            ]);

        $payments = DebtPayment::whereHas('companyDebt', fn ($q) => $q->where('supplier_id', $supplierId))
            ->with('companyDebt')
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '<=', $v))
            ->get()
            ->map(fn (DebtPayment $p) => [
                'date' => $p->payment_date,
                __('Date') => $p->payment_date?->format('d/m/Y'),
                __('Reference') => $p->companyDebt?->debt_number,
                __('Type') => __('Payment'),
                __('Description') => __('Debt payment'),
                __('Debit') => '—',
                __('Credit') => number_format((float) $p->amount, 2),
            ]);

        /** @var Collection $rows */
        $rows = $debts->concat($payments)
            ->sortBy('date')
            ->values()
            ->map(fn (array $row) => collect($row)->except('date')->all());

        $totalDebt = (float) CompanyDebt::where('supplier_id', $supplierId)->sum('original_amount');
        $totalPaid = (float) DebtPayment::whereHas('companyDebt', fn ($q) => $q->where('supplier_id', $supplierId))->sum('amount');

        return [
            'columns' => $rows->first() ? array_keys($rows->first()) : [],
            'rows' => $rows,
            'summary' => [
                __('Total debts') => number_format($totalDebt, 2),
                __('Total paid') => number_format($totalPaid, 2),
                __('Outstanding balance') => number_format($totalDebt - $totalPaid, 2),
            ],
        ];
    }

    protected function transfers(array $f, User $user): array
    {
        $rows = StudentTransfer::query()
            ->visibleTo($user)
            ->with(['student', 'fromInstructor', 'toInstructor', 'transferredBy'])
            ->when($f['student_id'] ?? null, fn ($q, $v) => $q->where('student_id', $v))
            ->when($f['instructor_id'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub->where('from_instructor_id', $v)->orWhere('to_instructor_id', $v)))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('transfer_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('transfer_date', '<=', $v))
            ->orderByDesc('transfer_date')
            ->get()
            ->map(fn (StudentTransfer $t) => [
                __('Date') => $t->transfer_date?->format('d/m/Y'),
                __('Student') => $t->student?->full_name,
                __('From') => $t->fromInstructor?->full_name ?? '—',
                __('To') => $t->toInstructor?->full_name,
                __('Reason') => $t->reason,
                __('By') => $t->transferredBy?->name ?? '—',
            ]);

        return [
            'columns' => $rows->first() ? array_keys($rows->first()) : [],
            'rows' => $rows,
            'summary' => [__('Transfers') => $rows->count()],
        ];
    }
}
