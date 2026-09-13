<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CompanyDebt;
use App\Models\CompanyExpense;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every figure below is read from MySQL — nothing here is hard coded.
 */
class DashboardService
{
    public function adminMetrics(): array
    {
        $today = Carbon::today();

        return [
            'total_income' => (float) StudentPayment::sum('amount'),
            'total_expenses' => (float) CompanyExpense::sum('amount'),
            'net_profit' => round((float) StudentPayment::sum('amount') - (float) CompanyExpense::sum('amount'), 2),
            'outstanding_debt' => (float) CompanyDebt::outstanding()->sum('remaining_amount'),
            'active_students' => Student::where('status', 'active')->count(),
            'checkins_today' => Attendance::whereDate('attendance_date', $today)->where('status', 'present')->count(),
            'registrations_this_month' => Student::whereBetween('start_date', [
                $today->copy()->startOfMonth(),
                $today->copy()->endOfMonth(),
            ])->count(),
            'near_completion' => $this->nearCompletionQuery()->count(),
            'total_students' => Student::count(),
            'total_instructors' => Instructor::where('status', 'active')->count(),
            'total_vehicles' => Vehicle::count(),
            'lessons_today' => Lesson::whereDate('lesson_date', $today)->count(),
        ];
    }

    /** Daily attendance counts for the last N days. */
    public function attendanceTrend(int $days = 14, ?int $instructorId = null, ?int $studentId = null): Collection
    {
        $from = Carbon::today()->subDays($days - 1);

        $rows = Attendance::query()
            ->when($instructorId, fn ($q) => $q->where('instructor_id', $instructorId))
            ->when($studentId, fn ($q) => $q->where('student_id', $studentId))
            ->whereDate('attendance_date', '>=', $from)
            ->selectRaw('attendance_date, sum(status = ?) as present_count, count(*) as total_count', ['present'])
            ->groupBy('attendance_date')
            ->pluck('present_count', 'attendance_date');

        return collect(range(0, $days - 1))->map(function (int $offset) use ($from, $rows) {
            $date = $from->copy()->addDays($offset);

            return [
                'date' => $date->toDateString(),
                'label' => $date->format('d M'),
                'value' => (int) ($rows[$date->toDateString()] ?? 0),
            ];
        });
    }

    /** Monthly income vs expenses for the last N months. */
    public function incomeTrend(int $months = 6): Collection
    {
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);

        $income = StudentPayment::query()
            ->whereDate('payment_date', '>=', $start)
            ->selectRaw("DATE_FORMAT(payment_date, '%Y-%m') as period, sum(amount) as total")
            ->groupBy('period')
            ->pluck('total', 'period');

        $expenses = CompanyExpense::query()
            ->whereDate('expense_date', '>=', $start)
            ->selectRaw("DATE_FORMAT(expense_date, '%Y-%m') as period, sum(amount) as total")
            ->groupBy('period')
            ->pluck('total', 'period');

        return collect(range(0, $months - 1))->map(function (int $offset) use ($start, $income, $expenses) {
            $month = $start->copy()->addMonths($offset);
            $key = $month->format('Y-m');

            return [
                'label' => $month->format('M Y'),
                'income' => round((float) ($income[$key] ?? 0), 2),
                'expenses' => round((float) ($expenses[$key] ?? 0), 2),
            ];
        });
    }

    public function nearCompletionQuery(int $threshold = 80)
    {
        return Student::query()
            ->where('status', 'active')
            ->where('required_training_days', '>', 0)
            ->whereRaw(
                '(select count(distinct a.attendance_date) from attendance a
                    where a.student_id = students.id and a.status = ? and a.deleted_at is null)
                 >= (students.required_training_days * ? / 100)',
                ['present', $threshold]
            );
    }

    /* ----------------------------------------------------------------
     | Instructor — scoped to one instructor, nothing company-wide
     | ---------------------------------------------------------------- */

    public function instructorMetrics(Instructor $instructor): array
    {
        $today = Carbon::today();

        $studentIds = Student::where('current_instructor_id', $instructor->id)->pluck('id');

        return [
            'my_students' => $studentIds->count(),
            'present_today' => Attendance::whereIn('student_id', $studentIds)
                ->whereDate('attendance_date', $today)->where('status', 'present')->count(),
            'absent_today' => Attendance::whereIn('student_id', $studentIds)
                ->whereDate('attendance_date', $today)->where('status', 'absent')->count(),
            'lessons_today' => Lesson::where('instructor_id', $instructor->id)
                ->whereDate('lesson_date', $today)->count(),
            'near_completion' => $this->nearCompletionQuery()
                ->where('current_instructor_id', $instructor->id)->count(),
            'my_vehicles' => Vehicle::where('instructor_id', $instructor->id)->count(),
            'outstanding_loan' => (float) $instructor->loans()
                ->whereIn('status', ['outstanding', 'partially_paid'])->sum('remaining_amount'),
            'total_loan' => (float) $instructor->loans()->where('status', '!=', 'cancelled')->sum('amount'),
        ];
    }

    public function studentMetrics(Student $student): array
    {
        return [
            'required_days' => (int) $student->required_training_days,
            'completed_days' => $student->completed_days,
            'remaining_days' => $student->remaining_days,
            'progress' => $student->progress_percentage,
            'lessons' => $student->lessons()->count(),
            'present_days' => $student->attendance()->where('status', 'present')->count(),
            'absent_days' => $student->attendance()->where('status', 'absent')->count(),
        ];
    }
}
