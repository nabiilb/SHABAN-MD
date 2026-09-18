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

    /* ================================================================
     | Imported students wait for their first real check-in
     | ================================================================ */

    /** A student carrying an imported opening balance and no real attendance. */
    private function imported(string $name, int $remaining, string $from): Student
    {
        $student = $this->makeStudent($name, $this->instructor, ['start_date' => '2026-08-01']);

        $student->forceFill([
            'required_training_days' => 30,
            'opening_remaining_days' => $remaining,
            'opening_remaining_from' => $from,
        ])->save();

        return $student->refresh();
    }

    /** 1 — no real attendance yet, so no absence is written for them. */
    public function test_an_imported_student_with_no_real_attendance_is_skipped(): void
    {
        $imported = $this->imported('Imported One', 15, '2026-09-18');

        $result = $this->absences()->markAbsent('2026-09-18');

        // Only the ordinary student.
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['students'], 'The imported student is not even considered.');
        $this->assertSame(0, Attendance::where('student_id', $imported->id)->count());
        $this->assertSame($this->active->id, Attendance::firstOrFail()->student_id);
    }

    /** 2 — and days may pass without any of them being filled in behind them. */
    public function test_days_before_the_first_real_attendance_stay_empty(): void
    {
        $imported = $this->imported('Imported One', 15, '2026-09-14');

        foreach (['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18'] as $day) {
            $this->absences()->markAbsent($day);
        }

        $this->assertSame(
            0,
            Attendance::where('student_id', $imported->id)->count(),
            'Five nights of the scheduler must leave an untouched student untouched.',
        );

        // The ordinary student got every one of those days.
        $this->assertSame(5, Attendance::where('student_id', $this->active->id)->count());
    }

    /** 3 — the first real check-in lets them into the ordinary rule. */
    public function test_a_real_check_in_starts_the_ordinary_absence_rule(): void
    {
        $imported = $this->imported('Imported One', 15, '2026-09-14');

        // Nothing on the 16th: still waiting.
        $this->absences()->markAbsent('2026-09-16');
        $this->assertSame(0, Attendance::where('student_id', $imported->id)->count());

        // They turn up on the 17th, for the first time in this system.
        $this->attend($imported, '2026-09-17');

        // The 18th finishes with nothing recorded, and now it counts.
        $result = $this->absences()->markAbsent('2026-09-18');

        $absence = Attendance::where('student_id', $imported->id)
            ->whereDate('attendance_date', '2026-09-18')
            ->firstOrFail();

        $this->assertSame('absent', $absence->status);
        $this->assertSame(2, $result['students'], 'Both students are considered now.');

        // The 17th is still their own present record, and the days before it
        // were never filled in.
        $this->assertSame(
            ['2026-09-17' => 'present', '2026-09-18' => 'absent'],
            Attendance::where('student_id', $imported->id)
                ->orderBy('attendance_date')
                ->get()
                ->mapWithKeys(fn ($a) => [$a->attendance_date->toDateString() => $a->status])
                ->all(),
        );
    }

    /** A check-in dated BEFORE the opening balance does not let them in. */
    public function test_attendance_before_the_opening_date_does_not_open_the_gate(): void
    {
        $imported = $this->imported('Imported One', 15, '2026-09-14');

        // A day from before the opening balance was taken — already inside the
        // number the register gave, and no evidence they are training now.
        $this->attend($imported, '2026-09-10');

        $this->absences()->markAbsent('2026-09-18');

        $this->assertSame(
            0,
            Attendance::where('student_id', $imported->id)->whereDate('attendance_date', '2026-09-18')->count(),
        );
    }

    /** A removed check-in does not hold the gate open either. */
    public function test_a_deleted_check_in_closes_the_gate_again(): void
    {
        $imported = $this->imported('Imported One', 15, '2026-09-14');

        $this->attend($imported, '2026-09-17')->delete();

        $this->absences()->markAbsent('2026-09-18');

        $this->assertSame(0, Attendance::where('student_id', $imported->id)->count());
    }

    /** 4 — an absence never eats into the register's remaining days. */
    public function test_an_absence_does_not_reduce_the_imported_remaining_days(): void
    {
        $imported = $this->imported('Imported One', 15, '2026-09-14');

        $this->attend($imported, '2026-09-17');
        $imported->refresh();

        // One real day trained: fourteen left, and the register's figure moved
        // by exactly that one day.
        $this->assertSame(14, $imported->remaining_days);
        $this->assertEqualsWithDelta(53.3, $imported->progress_percentage, 0.05);

        $this->absences()->markAbsent('2026-09-18');
        $imported->refresh();

        // The absence changed nothing: it is not a day of training.
        $this->assertSame(14, $imported->remaining_days, 'An absence is not progress.');
        $this->assertEqualsWithDelta(53.3, $imported->progress_percentage, 0.05);
        $this->assertSame(1, $imported->training_days_since_opening);
        $this->assertSame(15, $imported->opening_remaining_days, 'The opening balance itself is untouched.');
        $this->assertSame('2026-09-14', $imported->opening_remaining_from->toDateString());
    }

    /** 5 — and the ordinary student is entirely unaffected by any of this. */
    public function test_a_normal_student_is_unaffected_by_the_imported_rule(): void
    {
        $this->imported('Imported One', 15, '2026-09-14');

        $this->assertSame(1, $this->absences()->markAbsent('2026-09-18')['created']);

        $row = Attendance::where('student_id', $this->active->id)->firstOrFail();

        $this->assertSame('absent', $row->status);
        $this->assertNull($this->active->fresh()->opening_remaining_days, 'No opening balance, no gate.');
    }

    /** The dry run counts the same students a real run would write. */
    public function test_the_dry_run_applies_the_same_eligibility(): void
    {
        $this->imported('Imported One', 15, '2026-09-18');

        $this->artisan('attendance:mark-absent', ['--date' => '2026-09-18', '--dry-run' => true])
            ->expectsOutputToContain('1 student(s) would be marked absent')
            ->expectsOutputToContain('1 active student(s) considered')
            ->assertSuccessful();

        $this->assertSame(1, $this->absences()->markAbsent('2026-09-18')['created']);
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
