<?php

namespace Tests\Unit;

use App\Models\Attendance;
use App\Models\Student;
use App\Services\StudentProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudentProgressTest extends TestCase
{
    use RefreshDatabase;

    private StudentProgressService $progress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->progress = app(StudentProgressService::class);
    }

    private function markPresent(Student $student, int $days, ?Carbon $from = null): void
    {
        $from ??= Carbon::today()->subDays($days);

        for ($day = 0; $day < $days; $day++) {
            Attendance::create([
                'student_id' => $student->id,
                'instructor_id' => $student->current_instructor_id,
                'attendance_date' => $from->copy()->addDays($day),
                'status' => 'present',
            ]);
        }
    }

    public function test_the_worked_example_from_the_specification(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');
        $student = $this->makeStudent('Ilyas', $instructor, ['required_training_days' => 24]);

        $this->markPresent($student, 18);
        $student->refresh();

        $this->assertSame(24, $student->required_training_days);
        $this->assertSame(18, $student->completed_days);
        $this->assertSame(6, $student->remaining_days);
        $this->assertSame(75.0, $student->progress_percentage);
    }

    public function test_remaining_days_never_go_below_zero(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');
        $student = $this->makeStudent('Over Achiever', $instructor, ['required_training_days' => 5]);

        $this->markPresent($student, 8);
        $student->refresh();

        $this->assertSame(8, $student->completed_days);
        $this->assertSame(0, $student->remaining_days);
        $this->assertSame(100.0, $student->progress_percentage, 'Progress is capped at 100%.');
    }

    public function test_progress_is_zero_when_no_training_days_are_required(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');
        $student = $this->makeStudent('No Requirement', $instructor, ['required_training_days' => 0]);

        $this->assertSame(0.0, $student->progress_percentage);
        $this->assertSame(0, $student->remaining_days);
    }

    public function test_only_present_days_count_towards_progress(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');
        $student = $this->makeStudent('Mixed', $instructor, ['required_training_days' => 10]);

        $this->markPresent($student, 4, Carbon::today()->subDays(10));

        foreach (['absent', 'excused', 'cancelled'] as $index => $status) {
            Attendance::create([
                'student_id' => $student->id,
                'instructor_id' => $instructor->id,
                'attendance_date' => Carbon::today()->subDays($index + 1),
                'status' => $status,
            ]);
        }

        $this->assertSame(4, $student->fresh()->completed_days);
    }

    public function test_a_student_is_completed_once_the_requirement_is_reached(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');
        $student = $this->makeStudent('Finisher', $instructor, ['required_training_days' => 5]);

        $start = Carbon::today()->subDays(10);
        $this->markPresent($student, 5, $start);

        $student = $this->progress->recalculate($student);

        $this->assertSame('completed', $student->status);
        $this->assertSame(0, $student->remaining_days);
        $this->assertSame(100.0, $student->progress_percentage);
        $this->assertSame(
            $start->copy()->addDays(4)->toDateString(),
            $student->completion_date->toDateString(),
            'The completion date is the final qualifying training day.',
        );
    }

    public function test_removing_attendance_reopens_a_completed_student(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');
        $student = $this->makeStudent('Finisher', $instructor, ['required_training_days' => 5]);

        $this->markPresent($student, 5);
        $student = $this->progress->recalculate($student);
        $this->assertSame('completed', $student->status);

        Attendance::latest('id')->first()->delete();
        $student = $this->progress->recalculate($student);

        $this->assertSame('active', $student->status);
        $this->assertNull($student->completion_date);
        $this->assertSame(4, $student->completed_days);
    }

    public function test_a_cancelled_student_is_not_promoted_to_completed(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');
        $student = $this->makeStudent('Dropped Out', $instructor, [
            'required_training_days' => 3,
            'status' => 'cancelled',
        ]);

        $this->markPresent($student, 3);
        $student = $this->progress->recalculate($student);

        $this->assertSame('cancelled', $student->status);
    }

    public function test_the_with_progress_scope_matches_the_per_model_calculation(): void
    {
        $instructor = $this->makeInstructor('Xasan Maxamuud');
        $a = $this->makeStudent('A', $instructor, ['required_training_days' => 24]);
        $b = $this->makeStudent('B', $instructor, ['required_training_days' => 24]);

        $this->markPresent($a, 18);
        $this->markPresent($b, 6);

        $rows = Student::withProgress()->orderBy('full_name')->get();

        $this->assertSame(18, $rows[0]->completed_days);
        $this->assertSame(75.0, $rows[0]->progress_percentage);
        $this->assertSame(6, $rows[1]->completed_days);
        $this->assertSame(25.0, $rows[1]->progress_percentage);
    }
}
