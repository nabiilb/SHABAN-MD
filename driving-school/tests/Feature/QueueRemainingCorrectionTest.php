<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\User;
use App\Services\StudentProgressService;
use App\Services\TrainingQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Putting right the days a student has left, from the queue dialog.
 *
 * There is no "remaining" column to set. Remaining is calculated, and the only
 * honest way to change the answer is to change what it is calculated from — so
 * the correction moves the opening balance and its baseline, exactly as the
 * register import does, and every screen keeps reading the same figure from
 * the same place.
 */
class QueueRemainingCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Instructor $instructor;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-21 09:00:00'));

        $this->teacher = $this->makeUser(Role::INSTRUCTOR);
        $this->instructor = $this->makeInstructor('Xasan Teacher', $this->teacher);

        // Fifteen days, nine of them trained: six left, sixty per cent through.
        $this->student = $this->makeStudent('Nasteexo Sadak', null, ['required_training_days' => 15]);

        foreach (range(1, 9) as $day) {
            Attendance::create([
                'student_id' => $this->student->id,
                'attendance_date' => Carbon::parse('2026-08-01')->addDays($day)->toDateString(),
                'status' => 'present',
                'check_in_time' => '09:00:00',
            ]);
        }
    }

    private function correct(?User $as, int $remaining, ?Student $student = null)
    {
        return $this->actingAs($as ?? $this->teacher)->patchJson(
            route('instructor.training.students.remaining', $student ?? $this->student),
            ['remaining_days' => $remaining],
        );
    }

    /* ------------------------------------------------------------------
       What the dialog shows, and what it can change
       ------------------------------------------------------------------ */

    public function test_the_search_result_carries_the_figure_the_editor_prefills(): void
    {
        $response = $this->actingAs($this->teacher)
            ->getJson(route('instructor.training.students.search', ['q' => 'Nasteexo']));

        $response->assertOk();

        $result = collect($response->json('results'))->firstWhere('id', $this->student->id);

        $this->assertSame(6, $result['remaining_days']);
        $this->assertEqualsWithDelta(60.0, $result['progress'], 0.05);
    }

    public function test_the_console_offers_the_editor(): void
    {
        $this->actingAs($this->teacher)->get(route('instructor.training.index'))
            ->assertOk()
            ->assertSee('Edit remaining days')
            ->assertSee('saveRemaining', false);
    }

    public function test_six_days_can_be_corrected_to_five(): void
    {
        $this->assertSame(6, $this->student->fresh()->remaining_days);

        $this->correct($this->teacher, 5)
            ->assertOk()
            ->assertJson(['remaining_days' => 5, 'progress' => 66.7]);

        $student = $this->student->fresh();

        $this->assertSame(5, $student->remaining_days);
        $this->assertSame(10, $student->effective_completed_days);
        $this->assertSame(66.7, $student->progress_percentage);
    }

    /* ------------------------------------------------------------------
       How it is stored
       ------------------------------------------------------------------ */

    public function test_it_moves_the_opening_balance_and_its_baseline(): void
    {
        $this->correct($this->teacher, 5)->assertOk();

        $onFile = DB::table('students')->where('id', $this->student->id)->first();

        $this->assertSame(5, (int) $onFile->opening_remaining_days);
        $this->assertSame('2026-09-21', substr((string) $onFile->opening_remaining_from, 0, 10));
    }

    public function test_the_progress_service_agrees_with_the_dialog(): void
    {
        $this->correct($this->teacher, 5)->assertOk();

        $summary = app(StudentProgressService::class)->summarise($this->student->fresh());

        $this->assertSame(15, $summary['required_days']);
        $this->assertSame(5, $summary['remaining_days']);
        $this->assertSame(10, $summary['completed_days']);
        $this->assertSame(66.7, $summary['progress']);
    }

    public function test_training_from_today_on_keeps_counting_the_balance_down(): void
    {
        $this->correct($this->teacher, 5)->assertOk();

        Attendance::create([
            'student_id' => $this->student->id,
            'attendance_date' => '2026-09-22',
            'status' => 'present',
            'check_in_time' => '09:00:00',
        ]);

        $this->assertSame(4, $this->student->fresh()->remaining_days);
    }

    public function test_a_day_already_trained_today_is_not_taken_off_twice(): void
    {
        Attendance::create([
            'student_id' => $this->student->id,
            'attendance_date' => '2026-09-21',
            'status' => 'present',
            'check_in_time' => '08:00:00',
        ]);

        $this->correct($this->teacher, 5)->assertOk();

        // The teacher said five are left today, and five are left today.
        $this->assertSame(5, $this->student->fresh()->remaining_days);
    }

    /* ------------------------------------------------------------------
       What it must not do
       ------------------------------------------------------------------ */

    public function test_it_writes_no_attendance_and_leaves_the_course_length_alone(): void
    {
        $before = [
            'attendance' => DB::table('attendance')->get()->toArray(),
            'required' => $this->student->required_training_days,
        ];

        $this->correct($this->teacher, 5)->assertOk();

        $this->assertEquals($before['attendance'], DB::table('attendance')->get()->toArray());
        $this->assertSame($before['required'], $this->student->fresh()->required_training_days);
        $this->assertSame(9, $this->student->fresh()->completed_days, 'real attendance is untouched');
    }

    public function test_it_touches_nothing_else_about_the_student(): void
    {
        $before = (array) DB::table('students')->where('id', $this->student->id)->first();

        $this->correct($this->teacher, 5)->assertOk();

        $after = (array) DB::table('students')->where('id', $this->student->id)->first();

        $changed = array_keys(array_diff_assoc(
            array_diff_key($after, ['updated_at' => null]),
            $before,
        ));

        sort($changed);

        $this->assertSame(['opening_remaining_days', 'opening_remaining_from'], $changed);
    }

    public function test_it_touches_no_other_record(): void
    {
        $student = $this->student;

        StudentPayment::create([
            'payment_number' => 'PAY-9001', 'student_id' => $student->id, 'amount' => 60,
            'payment_date' => '2026-08-01', 'payment_method' => 'cash',
        ]);

        $tables = ['student_payments', 'attendance', 'training_sessions', 'training_queue_entries',
            'training_evaluations', 'company_debts', 'instructors'];

        $before = [];

        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toArray();
        }

        $this->correct($this->teacher, 5)->assertOk();

        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->get()->toArray(), "It changed {$table}.");
        }
    }

    /* ------------------------------------------------------------------
       What it refuses
       ------------------------------------------------------------------ */

    public function test_a_negative_figure_is_refused(): void
    {
        $this->correct($this->teacher, -1)->assertStatus(422)->assertJsonValidationErrors('remaining_days');

        $this->assertSame(6, $this->student->fresh()->remaining_days);
        $this->assertNull($this->student->fresh()->opening_remaining_days);
    }

    public function test_more_days_than_the_course_has_is_refused(): void
    {
        $this->correct($this->teacher, 16)->assertStatus(422)->assertJsonValidationErrors('remaining_days');

        $this->assertSame(6, $this->student->fresh()->remaining_days);
    }

    public function test_the_whole_course_is_allowed_and_so_is_none_of_it(): void
    {
        $this->correct($this->teacher, 15)->assertOk();
        $this->assertSame(15, $this->student->fresh()->remaining_days);

        $this->correct($this->teacher, 0)->assertOk();
        $this->assertSame(0, $this->student->fresh()->remaining_days);

        // Nought days left is not by itself a decision that somebody finished.
        $this->assertSame('active', $this->student->fresh()->status);
    }

    public function test_a_completed_student_is_not_reopened_through_this_door(): void
    {
        $this->student->forceFill(['status' => 'completed'])->save();

        $this->correct($this->teacher, 5)->assertStatus(422);

        $this->assertSame('completed', $this->student->fresh()->status);
        $this->assertNull($this->student->fresh()->opening_remaining_days);
    }

    public function test_somebody_with_no_business_here_is_refused(): void
    {
        // A student's own login has no business on the training console.
        $this->correct($this->makeUser(Role::STUDENT), 5)->assertForbidden();
        $this->assertSame(6, $this->student->fresh()->remaining_days);

        // And a request with nobody behind it at all.
        $this->patchJson(
            route('instructor.training.students.remaining', $this->student),
            ['remaining_days' => 5],
        )->assertForbidden();
    }

    /* ------------------------------------------------------------------
       And then the queue, as before
       ------------------------------------------------------------------ */

    public function test_correcting_does_not_queue_anybody(): void
    {
        $this->correct($this->teacher, 5)->assertOk();

        $this->assertSame(0, DB::table('training_queue_entries')->count());
    }

    public function test_the_student_can_still_be_queued_afterwards(): void
    {
        $this->correct($this->teacher, 5)->assertOk();

        $this->actingAs($this->teacher)->post(route('instructor.training.queue.store'), [
            'student_id' => $this->student->id,
            'planned_minutes' => 30,
        ])->assertRedirect();

        $this->assertSame(1, DB::table('training_queue_entries')->count());
        $this->assertTrue(app(TrainingQueueService::class)->eligibilityFor($this->student->fresh())->eligible === false,
            'they are in an open cycle now, so a second entry is refused');
    }

    public function test_the_correction_is_written_to_the_audit_log(): void
    {
        $this->correct($this->teacher, 5)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student.remaining_corrected',
            'auditable_id' => $this->student->id,
            'user_id' => $this->teacher->id,
        ]);

        $entry = DB::table('audit_logs')->where('action', 'student.remaining_corrected')->first();

        $this->assertStringContainsString('from 6 to 5', $entry->description);
        $this->assertStringContainsString('training queue correction', $entry->new_values);
    }
}
