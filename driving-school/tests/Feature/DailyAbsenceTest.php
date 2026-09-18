<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\DailyAbsenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Closing off a finished day's register.
 *
 * The register is kept by check-in, so a student who does not train leaves no
 * row at all — and "did not attend" ends up looking exactly like "nobody wrote
 * anything down". Once a day is over, the difference is recorded.
 *
 * Over, though. Never the day in progress: a student who has not arrived by
 * lunchtime has not missed the day yet.
 */
class DailyAbsenceTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'Africa/Mogadishu';

    private string $originalTimezone;

    private User $admin;

    private Instructor $instructor;

    private Student $active;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        $this->seedReferenceData();

        // The school's own clock, which is what decides where yesterday ends.
        config(['app.timezone' => self::TIMEZONE]);
        date_default_timezone_set(self::TIMEZONE);
        Carbon::setTestNow(Carbon::parse('2026-09-19 09:00:00', self::TIMEZONE));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->instructor = $this->makeInstructor('Cabdi Yuusuf');

        $this->active = $this->makeStudent('Ahmed Ali', $this->instructor, [
            'start_date' => '2026-08-01',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        config(['app.timezone' => $this->originalTimezone]);
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    private function absences(): DailyAbsenceService
    {
        return app(DailyAbsenceService::class);
    }

    private function attend(Student $student, string $date, string $status = 'present'): Attendance
    {
        return Attendance::create([
            'student_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'attendance_date' => $date,
            'status' => $status,
            'recorded_by' => $this->admin->id,
        ]);
    }

    /* ================================================================
     | The rule
     | ================================================================ */

    public function test_an_active_student_with_no_record_is_marked_absent(): void
    {
        $result = $this->absences()->markAbsent('2026-09-18');

        $this->assertSame(1, $result['created']);
        $this->assertSame('2026-09-18', $result['date']);

        $row = Attendance::firstOrFail();

        $this->assertSame($this->active->id, $row->student_id);
        $this->assertSame('2026-09-18', $row->attendance_date->toDateString());
        $this->assertSame('absent', $row->status);
        $this->assertSame($this->instructor->id, $row->instructor_id);
        $this->assertStringContainsString('automatically', (string) $row->notes);
    }

    /** A day that was trained is left exactly as the teacher left it. */
    public function test_an_existing_present_record_is_never_touched(): void
    {
        $present = $this->attend($this->active, '2026-09-18');
        $before = $present->fresh()->getAttributes();

        $result = $this->absences()->markAbsent('2026-09-18');

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame($before, $present->fresh()->getAttributes());
        $this->assertSame(1, Attendance::count());
    }

    /** As is every other status somebody chose. */
    public function test_no_recorded_status_is_overwritten(): void
    {
        $recorded = [];

        foreach (['absent', 'excused', 'cancelled'] as $status) {
            $student = $this->makeStudent("Student {$status}", $this->instructor, ['start_date' => '2026-08-01']);
            $recorded[$status] = $this->attend($student, '2026-09-18', $status);
        }

        $result = $this->absences()->markAbsent('2026-09-18');

        // Only the one student who had nothing recorded.
        $this->assertSame(1, $result['created']);
        $this->assertSame(3, $result['skipped']);

        foreach ($recorded as $status => $row) {
            $this->assertSame(
                1,
                Attendance::where('student_id', $row->student_id)->whereDate('attendance_date', '2026-09-18')->count(),
                "A {$status} record must not be doubled.",
            );
            $this->assertSame($status, $row->fresh()->status, "A {$status} record must survive untouched.");
        }
    }

    /** Only active students. A finished or cancelled one is not absent. */
    public function test_only_active_students_are_marked(): void
    {
        foreach (['completed', 'cancelled', 'suspended'] as $status) {
            $student = $this->makeStudent("Not active {$status}", $this->instructor, ['start_date' => '2026-08-01']);
            $student->update(['status' => $status]);
        }

        $result = $this->absences()->markAbsent('2026-09-18');

        $this->assertSame(1, $result['created'], 'Only the one active student.');
        $this->assertSame(1, Attendance::count());
        $this->assertSame($this->active->id, Attendance::firstOrFail()->student_id);
    }

    /** A student who had not joined yet was not absent from that day. */
    public function test_a_student_who_joined_later_is_not_marked(): void
    {
        $this->makeStudent('Joined today', $this->instructor, ['start_date' => '2026-09-19']);

        $result = $this->absences()->markAbsent('2026-09-18');

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, Attendance::count());
    }

    /** A student with no instructor is still marked — most of the school has none. */
    public function test_a_student_with_no_instructor_is_still_marked(): void
    {
        $orphan = $this->makeStudent('No teacher', null, ['start_date' => '2026-08-01']);

        $this->absences()->markAbsent('2026-09-18');

        $row = Attendance::where('student_id', $orphan->id)->firstOrFail();

        $this->assertSame('absent', $row->status);
        $this->assertNull($row->instructor_id, 'An absence has no teacher to name.');
    }

    /* ================================================================
     | Idempotency and the day boundary
     | ================================================================ */

    public function test_running_it_twice_writes_one_row(): void
    {
        $this->absences()->markAbsent('2026-09-18');
        $second = $this->absences()->markAbsent('2026-09-18');

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, Attendance::count());
        $this->assertSame(
            1,
            Attendance::where('student_id', $this->active->id)->whereDate('attendance_date', '2026-09-18')->count(),
        );
    }

    public function test_the_command_is_idempotent(): void
    {
        $this->artisan('attendance:mark-absent', ['--date' => '2026-09-18'])
            ->expectsOutputToContain('Marked 1 student(s) absent')
            ->assertSuccessful();

        $this->artisan('attendance:mark-absent', ['--date' => '2026-09-18'])
            ->expectsOutputToContain('already has a record')
            ->assertSuccessful();

        $this->assertSame(1, Attendance::count());
    }

    /** Today is not over, so today is not absent. */
    public function test_the_current_day_is_never_marked(): void
    {
        $this->artisan('attendance:mark-absent', ['--date' => '2026-09-19'])
            ->expectsOutputToContain('has not')
            ->assertFailed();

        $this->artisan('attendance:mark-absent', ['--date' => '2026-09-20'])
            ->assertFailed();

        $this->assertSame(0, Attendance::count(), 'Neither today nor tomorrow may be written.');
    }

    /** Even at one minute to midnight, the day is still running. */
    public function test_the_day_is_not_over_until_it_is_over(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 23:59:00', self::TIMEZONE));

        $this->artisan('attendance:mark-absent', ['--date' => '2026-09-19'])->assertFailed();
        $this->assertSame(0, Attendance::count());

        // Half past midnight, when the scheduler runs: now it is yesterday.
        Carbon::setTestNow(Carbon::parse('2026-09-20 00:30:00', self::TIMEZONE));

        $this->artisan('attendance:mark-absent')->assertSuccessful();

        $this->assertSame('2026-09-19', Attendance::firstOrFail()->attendance_date->toDateString());
    }

    /** With no date, it closes off yesterday in the school's timezone. */
    public function test_the_default_date_is_yesterday(): void
    {
        $this->assertSame('2026-09-18', $this->absences()->defaultDate()->toDateString());

        $this->artisan('attendance:mark-absent')->assertSuccessful();

        $this->assertSame('2026-09-18', Attendance::firstOrFail()->attendance_date->toDateString());
    }

    /** An explicit date reaches back — but only when it is given. */
    public function test_an_explicit_date_is_honoured_and_history_is_not_swept_up(): void
    {
        $this->artisan('attendance:mark-absent', ['--date' => '2026-09-10'])->assertSuccessful();

        $this->assertSame('2026-09-10', Attendance::firstOrFail()->attendance_date->toDateString());

        // The default run does yesterday alone: the eight days in between are
        // left as they are rather than being filled in behind the school's back.
        $this->artisan('attendance:mark-absent')->assertSuccessful();

        $this->assertSame(
            ['2026-09-10', '2026-09-18'],
            Attendance::orderBy('attendance_date')->pluck('attendance_date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())->all(),
        );
        $this->assertSame(2, Attendance::count(), 'No history was backfilled.');
    }

    /** The dry run counts and writes nothing. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $this->artisan('attendance:mark-absent', ['--date' => '2026-09-18', '--dry-run' => true])
            ->expectsOutputToContain('1 student(s) would be marked absent')
            ->assertSuccessful();

        $this->assertSame(0, Attendance::count());
    }

    /** A soft-deleted record is still somebody's decision about that day. */
    public function test_a_removed_record_is_not_silently_replaced(): void
    {
        $this->attend($this->active, '2026-09-18')->delete();

        $result = $this->absences()->markAbsent('2026-09-18');

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, Attendance::count());
        $this->assertSame(1, Attendance::withTrashed()->count());
    }

    /** The absence counts towards progress like any other non-present day: not at all. */
    public function test_an_absence_does_not_advance_the_students_progress(): void
    {
        $this->active->update(['required_training_days' => 30]);

        $this->absences()->markAbsent('2026-09-18');

        $this->assertSame(0, $this->active->fresh()->completed_days);
        $this->assertSame(30, $this->active->fresh()->remaining_days);
        $this->assertSame(0.0, $this->active->fresh()->progress_percentage);
    }
}
