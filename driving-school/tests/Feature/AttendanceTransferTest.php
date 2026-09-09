<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The transfer-attendance scenario from the specification.
 *
 *   Ahmed is with Teacher A. On 09/09/2026 Teacher A transfers him to
 *   Teacher B. Today's attendance must move; 08/09/2026 must not.
 */
class AttendanceTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherAUser;

    private User $teacherBUser;

    private Instructor $teacherA;

    private Instructor $teacherB;

    private Student $ahmed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        // A fixed "today" so the dates in the specification are literal.
        Carbon::setTestNow(Carbon::parse('2026-09-09 09:30:00'));

        $this->teacherAUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Teacher A']);
        $this->teacherBUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Teacher B']);
        $this->teacherA = $this->makeInstructor('Teacher A', $this->teacherAUser);
        $this->teacherB = $this->makeInstructor('Teacher B', $this->teacherBUser);

        $this->ahmed = $this->makeStudent('Ahmed', $this->teacherA, [
            'start_date' => Carbon::parse('2026-08-01'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function record(string $date, ?Instructor $instructor = null): Attendance
    {
        return Attendance::create([
            'student_id' => $this->ahmed->id,
            'instructor_id' => ($instructor ?? $this->teacherA)->id,
            'attendance_date' => $date,
            'check_in_time' => '09:00',
            'status' => 'present',
        ]);
    }

    private function transfer(User $actor, ?int $toInstructorId = null): TestResponse
    {
        return $this->actingAs($actor)->post(route('instructor.attendance.transfer'), [
            'student_id' => $this->ahmed->id,
            'to_instructor_id' => $toInstructorId ?? $this->teacherB->id,
            'reason' => 'Instructor unavailable',
        ]);
    }

    /* ----------------------------------------------------------------
     | The worked example
     | ---------------------------------------------------------------- */

    public function test_todays_attendance_moves_and_the_previous_day_does_not(): void
    {
        $yesterday = $this->record('2026-09-08');
        $today = $this->record('2026-09-09');

        $this->transfer($this->teacherAUser)->assertRedirect();

        // 08/09 stays exactly where it was.
        $this->assertSame($this->teacherA->id, $yesterday->fresh()->instructor_id);
        $this->assertNull($yesterday->fresh()->transferred_at);

        // 09/09 belongs to Teacher B now.
        $today->refresh();
        $this->assertSame($this->teacherB->id, $today->instructor_id);
        $this->assertSame($this->teacherA->id, $today->transferred_from_instructor_id);
        $this->assertNotNull($today->transferred_at);
        $this->assertNotNull($today->student_transfer_id);
    }

    public function test_the_previous_instructor_no_longer_sees_the_student_in_todays_list(): void
    {
        $this->record('2026-09-08');
        $this->record('2026-09-09');

        $this->transfer($this->teacherAUser);

        $todayForA = Attendance::visibleTo($this->teacherAUser->fresh())
            ->whereDate('attendance_date', '2026-09-09')
            ->get();

        $this->assertCount(0, $todayForA, "Teacher A must not see Ahmed in today's attendance.");
    }

    public function test_the_previous_instructor_keeps_the_earlier_day(): void
    {
        $this->record('2026-09-08');
        $this->record('2026-09-09');

        $this->transfer($this->teacherAUser);

        $historyForA = Attendance::visibleTo($this->teacherAUser->fresh())->get();

        $this->assertCount(1, $historyForA);
        $this->assertSame('2026-09-08', $historyForA->first()->attendance_date->toDateString());
    }

    public function test_the_new_instructor_sees_today_but_not_the_earlier_day(): void
    {
        $this->record('2026-09-08');
        $this->record('2026-09-09');

        $this->transfer($this->teacherAUser);

        $forB = Attendance::visibleTo($this->teacherBUser->fresh())->get();

        $this->assertCount(1, $forB, 'Teacher B gets today only, never the earlier day.');
        $this->assertSame('2026-09-09', $forB->first()->attendance_date->toDateString());
    }

    public function test_the_attendance_screens_reflect_the_move(): void
    {
        $this->record('2026-09-08');
        $this->record('2026-09-09');

        $this->transfer($this->teacherAUser);

        // Teacher A's list: no Ahmed row for today, but 08/09 is still there.
        $this->actingAs($this->teacherAUser->fresh())
            ->get(route('instructor.attendance.index', ['date_from' => '2026-09-09']))
            ->assertOk()
            ->assertDontSee('Ahmed');

        $this->actingAs($this->teacherAUser->fresh())
            ->get(route('instructor.attendance.index', ['date_to' => '2026-09-08']))
            ->assertOk()
            ->assertSee('Ahmed');

        // Teacher B's list and check-in screen both show him.
        $this->actingAs($this->teacherBUser->fresh())
            ->get(route('instructor.attendance.index'))
            ->assertOk()
            ->assertSee('Ahmed');

        $this->actingAs($this->teacherBUser->fresh())
            ->get(route('instructor.attendance.create'))
            ->assertOk()
            ->assertSee('Ahmed');

        // And he is gone from Teacher A's check-in screen.
        $this->actingAs($this->teacherAUser->fresh())
            ->get(route('instructor.attendance.create'))
            ->assertOk()
            ->assertDontSee('Ahmed');
    }

    public function test_no_duplicate_row_is_created_for_the_day(): void
    {
        $this->record('2026-09-08');
        $this->record('2026-09-09');

        $this->transfer($this->teacherAUser);

        $this->assertSame(2, Attendance::count(), 'The row is moved, never copied.');
        $this->assertSame(
            1,
            Attendance::where('student_id', $this->ahmed->id)->whereDate('attendance_date', '2026-09-09')->count(),
            'The student appears exactly once for the day.',
        );
    }

    public function test_a_transfer_works_before_the_student_is_checked_in(): void
    {
        $this->transfer($this->teacherAUser)->assertRedirect();

        $this->assertSame($this->teacherB->id, $this->ahmed->fresh()->current_instructor_id);
        $this->assertSame(0, Attendance::count());

        // Teacher B can now check him in, and the row lands on Teacher B.
        $this->actingAs($this->teacherBUser->fresh())
            ->post(route('instructor.attendance.store'), [
                'student_id' => $this->ahmed->id,
                'attendance_date' => '2026-09-09',
                'status' => 'present',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('attendance', [
            'student_id' => $this->ahmed->id,
            'instructor_id' => $this->teacherB->id,
            'attendance_date' => '2026-09-09',
        ]);
    }

    public function test_the_student_and_the_assignment_history_move_too(): void
    {
        $this->transfer($this->teacherAUser);

        $this->assertSame($this->teacherB->id, $this->ahmed->fresh()->current_instructor_id);

        $assignments = $this->ahmed->assignments()->orderBy('assigned_from')->get();
        $this->assertCount(1, $assignments->where('is_current', true));
        $this->assertSame($this->teacherB->id, $assignments->firstWhere('is_current', true)->instructor_id);

        $this->assertDatabaseHas('student_transfers', [
            'student_id' => $this->ahmed->id,
            'from_instructor_id' => $this->teacherA->id,
            'to_instructor_id' => $this->teacherB->id,
            'transfer_date' => '2026-09-09',
        ]);
    }

    public function test_the_move_is_written_to_the_audit_log(): void
    {
        $this->record('2026-09-09');
        $this->transfer($this->teacherAUser);

        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.transferred']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.transferred']);
    }

    /* ----------------------------------------------------------------
     | Authorization and validation
     | ---------------------------------------------------------------- */

    public function test_an_instructor_cannot_transfer_a_student_who_is_not_his(): void
    {
        $this->record('2026-09-09');

        // Teacher B does not have Ahmed yet.
        $this->transfer($this->teacherBUser, $this->teacherA->id)->assertForbidden();

        $this->assertSame($this->teacherA->id, $this->ahmed->fresh()->current_instructor_id);
        $this->assertDatabaseCount('student_transfers', 0);
    }

    public function test_transferring_to_the_current_instructor_is_rejected(): void
    {
        $this->transfer($this->teacherAUser, $this->teacherA->id)
            ->assertSessionHasErrors('to_instructor_id');

        $this->assertDatabaseCount('student_transfers', 0);
    }

    public function test_transferring_to_an_inactive_instructor_is_rejected(): void
    {
        $this->teacherB->update(['status' => 'suspended']);

        $this->transfer($this->teacherAUser)->assertSessionHasErrors('to_instructor_id');

        $this->assertSame($this->teacherA->id, $this->ahmed->fresh()->current_instructor_id);
    }

    public function test_transferring_to_a_nonexistent_instructor_is_rejected(): void
    {
        $this->transfer($this->teacherAUser, 99999)->assertSessionHasErrors('to_instructor_id');
    }

    public function test_a_student_cannot_transfer_anyone(): void
    {
        $studentUser = $this->makeUser(Role::STUDENT);
        $this->ahmed->forceFill(['user_id' => $studentUser->id])->save();

        $this->actingAs($studentUser)
            ->post(route('instructor.attendance.transfer'), [
                'student_id' => $this->ahmed->id,
                'to_instructor_id' => $this->teacherB->id,
            ])
            ->assertForbidden();

        $this->assertSame($this->teacherA->id, $this->ahmed->fresh()->current_instructor_id);
    }

    public function test_an_admin_can_transfer_from_the_attendance_screen(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $yesterday = $this->record('2026-09-08');
        $today = $this->record('2026-09-09');

        $this->actingAs($admin)
            ->post(route('admin.attendance.transfer'), [
                'student_id' => $this->ahmed->id,
                'to_instructor_id' => $this->teacherB->id,
            ])
            ->assertRedirect();

        $this->assertSame($this->teacherB->id, $today->fresh()->instructor_id);
        $this->assertSame($this->teacherA->id, $yesterday->fresh()->instructor_id);
    }

    /* ----------------------------------------------------------------
     | Transfers dated earlier never rewrite history
     | ---------------------------------------------------------------- */

    public function test_an_admin_transfer_dated_earlier_moves_only_that_day(): void
    {
        $sept7 = $this->record('2026-09-07');
        $sept8 = $this->record('2026-09-08');
        $sept9 = $this->record('2026-09-09');

        // The Transfers page allows an explicit date; it is still date-specific.
        app(StudentTransferService::class)->transfer(
            $this->ahmed,
            $this->teacherB,
            'Backdated correction',
            $this->makeUser(Role::ADMIN),
            Carbon::parse('2026-09-08'),
        );

        $this->assertSame($this->teacherA->id, $sept7->fresh()->instructor_id, '07/09 is untouched.');
        $this->assertSame($this->teacherB->id, $sept8->fresh()->instructor_id, '08/09 is the transfer date.');
        $this->assertSame($this->teacherA->id, $sept9->fresh()->instructor_id, '09/09 is a different day.');
    }
}
