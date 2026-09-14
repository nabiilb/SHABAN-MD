<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\LessonTopic;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Student;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\ReportService;
use App\Services\TrainingBoardService;
use App\Services\TrainingQueueService;
use App\Services\TrainingSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the school's day would look like on Mogadishu time.
 *
 * The application runs on UTC while the school runs on UTC+3, so between
 * midnight and 03:00 local the app is still on yesterday: a student checked in
 * at 00:30 is filed under the previous day, a queue opened at 00:30 carries
 * the previous day's date, and "Completed Today" is the wrong today.
 *
 * These tests do not change the application's timezone. Each one sets the
 * timezone it is testing for the duration of the test, so the effect of the
 * change can be seen and relied on before anybody flips the setting — and so
 * this file keeps passing whichever way the setting ends up.
 */
class BusinessTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /** 00:30 on 15 September in Mogadishu — 21:30 on the 14th in UTC. */
    private const LATE_NIGHT = '2026-09-14T21:30:00Z';

    private const BUSINESS_TIMEZONE = 'Africa/Mogadishu';

    private string $originalTimezone;

    private User $admin;

    private User $teacherUser;

    private Instructor $teacher;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        $this->seedReferenceData();

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->teacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Cabdi']);
        $this->teacher = $this->makeInstructor('Cabdi', $this->teacherUser);
        $this->student = $this->makeStudent('Ali HASSAN', $this->teacher);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->useTimezone($this->originalTimezone);
        parent::tearDown();
    }

    /** Runs the rest of the test as if the app were configured for this zone. */
    private function useTimezone(string $timezone): void
    {
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }

    private function atLateNight(string $timezone): void
    {
        $this->useTimezone($timezone);
        Carbon::setTestNow(Carbon::parse(self::LATE_NIGHT));
    }

    /* G1 — 00:30 Mogadishu is the 15th, not the 14th. */
    public function test_half_past_midnight_in_mogadishu_is_the_local_date(): void
    {
        $this->atLateNight('UTC');
        $this->assertSame('2026-09-14', today()->toDateString(), 'On UTC the app is still on yesterday.');
        $this->assertSame('21:30', now()->format('H:i'));

        $this->atLateNight(self::BUSINESS_TIMEZONE);
        $this->assertSame('2026-09-15', today()->toDateString());
        $this->assertSame('00:30', now()->format('H:i'));
    }

    /* G2 — the queue opens on the right day. */
    public function test_the_queue_date_follows_the_business_day(): void
    {
        $this->atLateNight(self::BUSINESS_TIMEZONE);

        app(TrainingQueueService::class)->add($this->student, $this->admin);

        $entry = TrainingQueueEntry::firstOrFail();

        $this->assertSame('2026-09-15', $entry->queue_date->toDateString());
        $this->assertSame(0, $entry->waiting_minutes, 'A student queued this minute has waited no minutes.');

        // And the console asks for the same day, so they are visible.
        $this->assertSame(
            ['Ali HASSAN'],
            collect(app(TrainingBoardService::class)->board($this->teacherUser)['queue'])->pluck('student')->all(),
        );
    }

    /* G3 — Completed Today means today in Mogadishu. */
    public function test_completed_today_uses_the_business_day(): void
    {
        Setting::put('training_auto_start_next', '0');

        // 23:40 local on the 14th — the evening before.
        $this->useTimezone(self::BUSINESS_TIMEZONE);
        Carbon::setTestNow(Carbon::parse('2026-09-14T20:40:00Z'));

        $yesterday = $this->runOneSession();
        $this->assertSame('2026-09-14', $yesterday->started_at->toDateString());

        // 00:30 local on the 15th.
        Carbon::setTestNow(Carbon::parse(self::LATE_NIGHT));

        $today = $this->runOneSession();
        $this->assertSame('2026-09-15', $today->started_at->toDateString());

        $completed = app(TrainingBoardService::class)->completedToday($this->teacher->id);

        $this->assertSame([$today->id], $completed->pluck('id')->all());
        $this->assertDatabaseHas('training_sessions', ['id' => $yesterday->id, 'status' => TrainingSession::COMPLETED]);
    }

    /* G4 — an attendance taken at 00:30 belongs to the new day. */
    public function test_attendance_lands_on_the_business_day(): void
    {
        $this->atLateNight(self::BUSINESS_TIMEZONE);

        $this->actingAs($this->teacherUser)->post(route('instructor.attendance.store'), [
            'student_id' => $this->student->id,
            'attendance_date' => today()->toDateString(),
            'check_in_time' => now()->format('H:i'),
            'status' => 'present',
            'lesson_topic_id' => LessonTopic::where('code', 'driving_practice')->value('id'),
        ])->assertRedirect();

        $attendance = Attendance::firstOrFail();

        $this->assertSame('2026-09-15', $attendance->attendance_date->toDateString());
        $this->assertSame('00:30:00', $attendance->check_in_time);
    }

    /* G5 — a report for the 15th finds the 00:30 record. */
    public function test_reports_read_business_dates(): void
    {
        $this->atLateNight(self::BUSINESS_TIMEZONE);

        Attendance::create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->teacher->id,
            'attendance_date' => today(),
            'status' => 'present',
        ]);

        $report = app(ReportService::class)->build('attendance', [
            'date_from' => '2026-09-15',
            'date_to' => '2026-09-15',
        ], $this->admin);

        $this->assertCount(1, $report['rows']);

        $empty = app(ReportService::class)->build('attendance', [
            'date_from' => '2026-09-14',
            'date_to' => '2026-09-14',
        ], $this->admin);

        $this->assertCount(0, $empty['rows']);
    }

    /* G6 — nothing is shifted twice. */
    public function test_a_stored_time_reads_back_as_the_same_wall_clock(): void
    {
        $this->atLateNight(self::BUSINESS_TIMEZONE);

        $session = $this->startOneSession();

        // What PHP wrote is what the column holds, and what Eloquent returns.
        $raw = DB::table('training_sessions')
            ->where('id', $session->id)->value('started_at');

        $this->assertSame('2026-09-15 00:30:00', (string) $raw);
        $this->assertSame('2026-09-15 00:30:00', $session->fresh()->started_at->format('Y-m-d H:i:s'));

        // A date-only column is a calendar day and carries no offset at all.
        $this->assertSame('2026-09-15', (string) DB::table('training_queue_entries')
            ->where('id', $session->training_queue_entry_id)->value('queue_date'));
    }

    /**
     * The same instant read under each zone: the date-only column does not
     * move, the timestamp column is read three hours apart.
     */
    public function test_only_timestamps_move_between_the_two_zones(): void
    {
        $this->atLateNight(self::BUSINESS_TIMEZONE);
        $session = $this->startOneSession();

        $storedTimestamp = (string) DB::table('training_sessions')
            ->where('id', $session->id)->value('started_at');
        $storedDate = (string) DB::table('training_queue_entries')
            ->where('id', $session->training_queue_entry_id)->value('queue_date');

        // Reading the very same rows as a UTC application.
        $this->useTimezone('UTC');

        $this->assertSame('2026-09-15 00:30:00', $storedTimestamp, 'The stored characters never change.');
        $this->assertSame('2026-09-15', $storedDate);

        // Which is why a converted timestamp must be converted exactly once:
        // the column holds wall-clock, not an instant.
        $this->assertSame(
            '2026-09-14 21:30:00',
            Carbon::parse($storedTimestamp, self::BUSINESS_TIMEZONE)->setTimezone('UTC')->format('Y-m-d H:i:s'),
        );
    }

    private function startOneSession(): TrainingSession
    {
        app(TrainingQueueService::class)->add($this->student, $this->admin);

        return app(TrainingSessionService::class)->startNext($this->teacher, $this->teacherUser, [
            'assigned_duration_minutes' => 30,
        ]);
    }

    private function runOneSession(): TrainingSession
    {
        $session = $this->startOneSession();

        app(TrainingSessionService::class)->end($session, $this->teacherUser);
        app(TrainingSessionService::class)->evaluate($session->fresh(), [
            'attendance_status' => 'present',
            'evaluation' => 'good',
        ], $this->teacherUser);

        return $session->fresh();
    }
}
