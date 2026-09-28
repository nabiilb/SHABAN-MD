<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\User;
use App\Services\StudentProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Correcting the days a student has left, from the admin's student form.
 *
 * The same calculation the instructor's queue dialog corrects, reached from
 * the other end of the building: one service, one opening balance, no second
 * column and no second formula. The administrator gets one thing the
 * instructor does not — the standing to reopen a student the school had
 * finished with, because a record that says "completed" with five days left
 * is worse than either of the two things it could mean.
 */
class AdminStudentRemainingEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-28 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);

        // Fifteen days, nine trained: six left, sixty per cent through.
        $this->student = $this->makeStudent('Aaliya Abdulkadir Hashi', null, ['required_training_days' => 15]);

        foreach (range(1, 9) as $day) {
            Attendance::create([
                'student_id' => $this->student->id,
                'attendance_date' => Carbon::parse('2026-08-01')->addDays($day)->toDateString(),
                'status' => 'present',
                'check_in_time' => '09:00:00',
            ]);
        }
    }

    /** The form as the browser posts it, with one field overridden. */
    private function submit(array $overrides = [])
    {
        $student = $this->student->fresh();

        return $this->actingAs($this->admin)->put(route('admin.students.update', $student), array_merge([
            'full_name' => $student->full_name,
            'phone' => $student->phone,
            'start_date' => $student->start_date->format('Y-m-d'),
            'required_training_days' => $student->required_training_days,
            'status' => $student->status,
            'remaining_training_days' => $student->remaining_days,
        ], $overrides));
    }

    /* ------------------------------------------------------------------
       What the form shows
       ------------------------------------------------------------------ */

    public function test_the_edit_form_offers_remaining_training_days(): void
    {
        $this->actingAs($this->admin)->get(route('admin.students.edit', $this->student))
            ->assertOk()
            ->assertSee('Remaining Training Days')
            ->assertSee('name="remaining_training_days"', false)
            ->assertSee('It does not create attendance records.', false);
    }

    public function test_the_field_shows_the_canonical_figure_not_the_raw_balance(): void
    {
        // Nothing is stored in opening_remaining_days at all here…
        $this->assertNull($this->student->opening_remaining_days);

        // …and the form still shows the six days the calculation gives.
        $this->actingAs($this->admin)->get(route('admin.students.edit', $this->student))
            ->assertOk()
            ->assertSee('value="6"', false);
    }

    public function test_the_registration_form_does_not_offer_it(): void
    {
        $this->actingAs($this->admin)->get(route('admin.students.create'))
            ->assertOk()
            ->assertDontSee('name="remaining_training_days"', false);
    }

    /* ------------------------------------------------------------------
       Correcting it
       ------------------------------------------------------------------ */

    public function test_the_admin_can_correct_six_days_to_five(): void
    {
        $this->submit(['remaining_training_days' => 5])->assertRedirect();

        $student = $this->student->fresh();

        $this->assertSame(5, $student->remaining_days);
        $this->assertSame(10, $student->effective_completed_days);
        $this->assertSame(66.7, $student->progress_percentage);
    }

    public function test_the_progress_service_agrees_after_the_save(): void
    {
        $this->submit(['remaining_training_days' => 10])->assertRedirect();

        $summary = app(StudentProgressService::class)->summarise($this->student->fresh());

        $this->assertSame(15, $summary['required_days']);
        $this->assertSame(10, $summary['remaining_days']);
        $this->assertSame(5, $summary['completed_days']);
        $this->assertSame(33.3, $summary['progress']);
    }

    public function test_it_moves_the_opening_balance_and_nothing_else_about_the_course(): void
    {
        $this->submit(['remaining_training_days' => 5])->assertRedirect();

        $onFile = DB::table('students')->where('id', $this->student->id)->first();

        $this->assertSame(5, (int) $onFile->opening_remaining_days);
        $this->assertSame('2026-09-28', substr((string) $onFile->opening_remaining_from, 0, 10));
        $this->assertSame(15, (int) $onFile->required_training_days);
    }

    public function test_leaving_the_field_alone_corrects_nothing(): void
    {
        $this->submit(['full_name' => 'Aaliya Abdulkadir Hashi Renamed'])->assertRedirect();

        $student = $this->student->fresh();

        $this->assertSame('Aaliya Abdulkadir Hashi Renamed', $student->full_name);
        $this->assertNull($student->opening_remaining_days, 'no balance was invented');
        $this->assertSame(6, $student->remaining_days);
    }

    public function test_no_attendance_is_written(): void
    {
        $before = DB::table('attendance')->get()->toArray();

        $this->submit(['remaining_training_days' => 1])->assertRedirect();

        $this->assertEquals($before, DB::table('attendance')->get()->toArray());
        $this->assertSame(9, $this->student->fresh()->completed_days, 'real attendance is untouched');
    }

    /* ------------------------------------------------------------------
       Required and Remaining, changed together
       ------------------------------------------------------------------ */

    public function test_both_can_be_changed_in_one_save(): void
    {
        $this->submit([
            'required_training_days' => 20,
            'remaining_training_days' => 18,
        ])->assertRedirect();

        $student = $this->student->fresh();

        $this->assertSame(20, $student->required_training_days);
        $this->assertSame(18, $student->remaining_days);
        $this->assertSame(2, $student->effective_completed_days);
    }

    public function test_remaining_is_measured_against_the_newly_submitted_course_length(): void
    {
        // 18 is more than the 15 on file but fits the 20 being saved with it.
        $this->submit([
            'required_training_days' => 20,
            'remaining_training_days' => 18,
        ])->assertSessionHasNoErrors();

        // And 18 is refused when the course is being shortened to 16.
        $this->submit([
            'required_training_days' => 16,
            'remaining_training_days' => 18,
        ])->assertSessionHasErrors('remaining_training_days');
    }

    public function test_a_negative_figure_is_refused(): void
    {
        $this->submit(['remaining_training_days' => -1])
            ->assertSessionHasErrors('remaining_training_days');

        $this->assertSame(6, $this->student->fresh()->remaining_days);
    }

    public function test_more_days_than_the_course_has_is_refused(): void
    {
        $this->submit(['remaining_training_days' => 16])
            ->assertSessionHasErrors('remaining_training_days');

        $this->assertSame(6, $this->student->fresh()->remaining_days);
    }

    public function test_none_of_the_course_and_all_of_it_are_both_allowed(): void
    {
        $this->submit(['remaining_training_days' => 15])->assertSessionHasNoErrors();
        $this->assertSame(15, $this->student->fresh()->remaining_days);

        $this->submit(['remaining_training_days' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->student->fresh()->remaining_days);
    }

    /* ------------------------------------------------------------------
       The student the school had finished with
       ------------------------------------------------------------------ */

    public function test_a_completed_student_shows_no_days_left(): void
    {
        $this->student->forceFill(['status' => Student::COMPLETED])->save();

        $this->assertSame(0, $this->student->fresh()->remaining_days);

        $this->actingAs($this->admin)->get(route('admin.students.edit', $this->student))
            ->assertOk()
            ->assertSee('value="0"', false);
    }

    public function test_giving_a_completed_student_days_back_reopens_them(): void
    {
        $this->student->forceFill(['status' => Student::COMPLETED, 'completion_date' => '2026-09-01'])->save();

        $this->submit(['status' => Student::COMPLETED, 'remaining_training_days' => 5])->assertRedirect();

        $student = $this->student->fresh();

        $this->assertSame(Student::ACTIVE, $student->status, 'five days left is not a finished student');
        $this->assertNull($student->completion_date);
        $this->assertSame(5, $student->remaining_days);
        $this->assertSame(10, $student->effective_completed_days);
        $this->assertSame(66.7, $student->progress_percentage);
    }

    public function test_leaving_a_completed_student_at_nought_leaves_them_completed(): void
    {
        $this->student->forceFill(['status' => Student::COMPLETED])->save();

        $this->submit(['status' => Student::COMPLETED, 'remaining_training_days' => 0])->assertRedirect();

        $this->assertSame(Student::COMPLETED, $this->student->fresh()->status);
    }

    public function test_setting_an_active_student_to_nought_does_not_complete_them(): void
    {
        $this->submit(['remaining_training_days' => 0])->assertRedirect();

        $student = $this->student->fresh();

        $this->assertSame(0, $student->remaining_days);
        // The form's own status field decides that, and it said active.
        $this->assertSame(Student::ACTIVE, $student->status);
    }

    public function test_the_status_the_form_chose_is_still_honoured(): void
    {
        $this->submit(['status' => 'suspended'])->assertRedirect();

        $this->assertSame('suspended', $this->student->fresh()->status);
    }

    public function test_an_instructor_still_cannot_reopen_a_completed_student(): void
    {
        $teacher = $this->makeUser(Role::INSTRUCTOR);
        $this->makeInstructor('Xasan Teacher', $teacher);

        $this->student->forceFill(['status' => Student::COMPLETED])->save();

        $this->actingAs($teacher)->patchJson(
            route('instructor.training.students.remaining', $this->student),
            ['remaining_days' => 5],
        )->assertStatus(422);

        $this->assertSame(Student::COMPLETED, $this->student->fresh()->status);
        $this->assertNull($this->student->fresh()->opening_remaining_days);
    }

    /**
     * The permission itself, not the screen that happens to guard it.
     *
     * The instructor console refuses a completed student before the service is
     * ever reached, so that route alone would pass even if the service reopened
     * everybody. This asks the service directly.
     */
    public function test_without_the_standing_to_reopen_a_completed_student_stays_completed(): void
    {
        $this->student->forceFill(['status' => Student::COMPLETED])->save();

        app(StudentProgressService::class)->correctRemaining(
            $this->student->fresh(), 5, $this->admin, source: 'training queue correction',
        );

        $student = $this->student->fresh();

        $this->assertSame(Student::COMPLETED, $student->status);
        // The balance is stored, and a finished student still reads as nought.
        $this->assertSame(0, $student->remaining_days);
    }

    public function test_with_that_standing_the_same_call_reopens_them(): void
    {
        $this->student->forceFill(['status' => Student::COMPLETED])->save();

        app(StudentProgressService::class)->correctRemaining(
            $this->student->fresh(), 5, $this->admin,
            source: 'admin student edit', mayReopenCompleted: true,
        );

        $this->assertSame(Student::ACTIVE, $this->student->fresh()->status);
        $this->assertSame(5, $this->student->fresh()->remaining_days);
    }

    /* ------------------------------------------------------------------
       The audit trail, and everything it must not touch
       ------------------------------------------------------------------ */

    public function test_the_correction_is_audited_with_its_source(): void
    {
        $this->submit(['remaining_training_days' => 5])->assertRedirect();

        $entry = DB::table('audit_logs')->where('action', 'student.remaining_corrected')->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->admin->id, (int) $entry->user_id);
        $this->assertStringContainsString('from 6 to 5', $entry->description);
        $this->assertStringContainsString('admin student edit', $entry->new_values);
        $this->assertStringContainsString('opening_remaining_days', $entry->old_values);
        $this->assertStringContainsString('opening_remaining_from', $entry->old_values);
    }

    public function test_reopening_is_recorded_as_a_status_change(): void
    {
        $this->student->forceFill(['status' => Student::COMPLETED])->save();

        $this->submit(['status' => Student::COMPLETED, 'remaining_training_days' => 5])->assertRedirect();

        $entry = DB::table('audit_logs')->where('action', 'student.remaining_corrected')->first();

        $this->assertStringContainsString('Reopened from completed to active', $entry->description);
        $this->assertStringContainsString('completed', $entry->old_values);
        $this->assertStringContainsString('active', $entry->new_values);
    }

    public function test_it_touches_no_payment_finance_or_training_record(): void
    {
        StudentPayment::create([
            'payment_number' => 'PAY-9001', 'student_id' => $this->student->id, 'amount' => 60,
            'payment_date' => '2026-08-01', 'payment_method' => 'cash',
        ]);

        $tables = ['student_payments', 'attendance', 'company_expenses', 'company_debts',
            'training_sessions', 'training_queue_entries', 'training_evaluations', 'fuel_records'];

        $before = [];

        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toArray();
        }

        $this->submit(['remaining_training_days' => 5])->assertRedirect();

        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->get()->toArray(), "It changed {$table}.");
        }
    }

    public function test_only_the_two_balance_columns_move(): void
    {
        $before = (array) DB::table('students')->where('id', $this->student->id)->first();

        $this->submit(['remaining_training_days' => 5])->assertRedirect();

        $after = (array) DB::table('students')->where('id', $this->student->id)->first();

        $changed = array_keys(array_diff_assoc(
            array_diff_key($after, ['updated_at' => null]),
            $before,
        ));

        sort($changed);

        $this->assertSame(['opening_remaining_days', 'opening_remaining_from'], $changed);
    }

    public function test_the_students_list_reflects_the_correction(): void
    {
        $this->submit(['remaining_training_days' => 10])->assertRedirect();

        $this->actingAs($this->admin)->get(route('admin.students.index'))
            ->assertOk()
            ->assertSee('33.3');
    }
}
