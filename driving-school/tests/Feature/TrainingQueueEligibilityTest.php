<?php

namespace Tests\Feature;

use App\Models\Instructor;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentTransfer;
use App\Models\TrainingEvaluation;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\StudentTransferService;
use App\Services\TrainingBoardService;
use App\Services\TrainingEligibilityService;
use App\Services\TrainingQueueService;
use App\Services\TrainingSessionService;
use App\Support\QueueEligibility;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The three rules the waiting queue now runs on.
 *
 *   - It is manual. Nothing but Add Student ever creates an entry.
 *   - It is owned. A teacher may only queue the students they are responsible
 *     for that day, and no request parameter changes that.
 *   - It is rolling. Twelve hours from the moment a student finished, not
 *     "once per day" — midnight resets nothing.
 *
 * The school runs on Africa/Mogadishu, so everything here does too: a rule
 * about midnight is only worth testing in the timezone whose midnight it is.
 */
class TrainingQueueEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const BUSINESS_TIMEZONE = 'Africa/Mogadishu';

    private string $originalTimezone;

    private User $admin;

    private User $teacherUser;

    private User $otherUser;

    private Instructor $teacher;

    private Instructor $other;

    private Student $mine;

    private Student $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        $this->seedReferenceData();

        // 08:00 in Mogadishu on a Monday — the school's own clock.
        config(['app.timezone' => self::BUSINESS_TIMEZONE]);
        date_default_timezone_set(self::BUSINESS_TIMEZONE);
        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00', self::BUSINESS_TIMEZONE));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->teacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Xasan']);
        $this->otherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Nasteexo']);
        $this->teacher = $this->makeInstructor('Xasan', $this->teacherUser);
        $this->other = $this->makeInstructor('Nasteexo', $this->otherUser);

        $this->mine = $this->makeStudent('Ilyas CABDI', $this->teacher);
        $this->theirs = $this->makeStudent('Faadumo XUSEEN', $this->other);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        config(['app.timezone' => $this->originalTimezone]);
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    /* ----------------------------------------------------------------
     | Helpers
     | ---------------------------------------------------------------- */

    private function queue(): TrainingQueueService
    {
        return app(TrainingQueueService::class);
    }

    private function sessions(): TrainingSessionService
    {
        return app(TrainingSessionService::class);
    }

    private function eligibility(): TrainingEligibilityService
    {
        return app(TrainingEligibilityService::class);
    }

    private function addAs(User $user, Student $student)
    {
        return $this->actingAs($user)->post(route('instructor.training.queue.store'), [
            'student_id' => $student->id,
            'assigned_duration_minutes' => 30,
        ]);
    }

    /** @return array<int, string> the names the Add Student dialog offers. */
    private function offeredTo(User $user): array
    {
        return $this->actingAs($user)
            ->get(route('instructor.training.index'))
            ->viewData('addable')
            ->pluck('full_name')
            ->all();
    }

    /** Queue, train and evaluate one student through to completion. */
    private function completeOneSession(Student $student, ?Instructor $instructor = null, ?User $actor = null): TrainingSession
    {
        $instructor ??= $this->teacher;
        $actor ??= $this->teacherUser;

        Setting::put('training_auto_start_next', '0');

        $this->queue()->add($student, $this->admin);
        $session = $this->sessions()->startNext($instructor, $actor, ['assigned_duration_minutes' => 30]);
        $this->sessions()->end($session, $actor);
        $this->sessions()->evaluate($session->fresh(), [
            'attendance_status' => 'present',
            'evaluation' => 'good',
        ], $actor);

        return $session->fresh();
    }

    /* ================================================================
     | 12 — ownership and transfers
     | ================================================================ */

    /** 12.1 */
    public function test_a_teacher_sees_their_own_student_under_add_student(): void
    {
        $this->assertContains('Ilyas CABDI', $this->offeredTo($this->teacherUser));

        $verdict = $this->eligibility()->canQueueStudent($this->teacher, $this->mine);
        $this->assertTrue($verdict->eligible);
        $this->assertSame(QueueEligibility::ELIGIBLE, $verdict->code);
    }

    /** 12.2 */
    public function test_a_teacher_never_sees_another_teachers_student(): void
    {
        $offered = $this->offeredTo($this->teacherUser);

        $this->assertNotContains('Faadumo XUSEEN', $offered);
        $this->assertFalse($this->eligibility()->canQueueStudent($this->teacher, $this->theirs)->eligible);
        $this->assertSame(
            QueueEligibility::NOT_YOURS,
            $this->eligibility()->canQueueStudent($this->teacher, $this->theirs)->code,
        );
    }

    /**
     * 12.3 — the list is a convenience, not the control. Posting the id
     * straight at the route, as anyone editing the HTML would, is refused by
     * the server for the same reason the id was never offered.
     */
    public function test_a_teacher_cannot_forge_a_request_for_another_teachers_student(): void
    {
        $this->addAs($this->teacherUser, $this->theirs)
            ->assertSessionHasErrors(['student_id' => 'This student is assigned to another instructor.']);

        $this->assertSame(0, TrainingQueueEntry::count());
    }

    /**
     * 12.3b — and no instructor_id in the request is believed either: the
     * teacher is whoever is logged in.
     */
    public function test_an_instructor_id_in_the_request_is_ignored(): void
    {
        $this->actingAs($this->teacherUser)->post(route('instructor.training.queue.store'), [
            'student_id' => $this->theirs->id,
            'instructor_id' => $this->other->id,
            'assigned_duration_minutes' => 30,
        ])->assertSessionHasErrors(['student_id']);

        $this->assertSame(0, TrainingQueueEntry::count());
    }

    /** 12.4 */
    public function test_a_transfer_moves_the_student_between_the_two_add_student_lists(): void
    {
        app(StudentTransferService::class)->transfer(
            $this->mine,
            $this->other,
            'Teacher on leave',
            $this->admin,
        );

        $this->mine->refresh();

        // The old teacher can no longer see or queue them.
        $this->assertNotContains('Ilyas CABDI', $this->offeredTo($this->teacherUser));
        $this->addAs($this->teacherUser, $this->mine)
            ->assertSessionHasErrors(['student_id' => 'This student is assigned to another instructor.']);

        // The new one can do both.
        $this->assertContains('Ilyas CABDI', $this->offeredTo($this->otherUser));
        $this->addAs($this->otherUser, $this->mine)->assertSessionHasNoErrors();

        $entry = TrainingQueueEntry::where('student_id', $this->mine->id)->firstOrFail();
        $this->assertSame(TrainingQueueEntry::WAITING, $entry->status);
        $this->assertSame($this->otherUser->id, $entry->created_by);
    }

    /**
     * 12.5 — a transfer changes who owns them now, and nothing about before.
     *
     * Dated tomorrow, which is what a transfer of a day the student has already
     * trained means: the existing rule is that a transfer hands over its own
     * date's attendance, so transferring *today* would legitimately move
     * today's register row with them. Yesterday's, and every earlier day's,
     * must keep the teacher who actually taught it.
     */
    public function test_history_under_the_old_teacher_survives_the_transfer(): void
    {
        $session = $this->completeOneSession($this->mine);
        $evaluation = TrainingEvaluation::firstOrFail();
        $attendance = $evaluation->attendance;
        $queueEntry = TrainingQueueEntry::firstOrFail();

        app(StudentTransferService::class)->transfer(
            $this->mine,
            $this->other,
            'Teacher on leave',
            $this->admin,
            Carbon::tomorrow(),
        );

        // Ownership moved.
        $this->assertSame($this->other->id, $this->mine->fresh()->current_instructor_id);
        $this->assertSame(1, StudentTransfer::count());

        // Everything that happened before it did not.
        $this->assertDatabaseHas('training_sessions', [
            'id' => $session->id,
            'instructor_id' => $this->teacher->id,
            'status' => TrainingSession::COMPLETED,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('training_evaluations', [
            'id' => $evaluation->id,
            'instructor_id' => $this->teacher->id,
        ]);
        $this->assertDatabaseHas('training_queue_entries', [
            'id' => $queueEntry->id,
            'status' => TrainingQueueEntry::COMPLETED,
        ]);
        $this->assertNotNull($attendance);
        $this->assertSame(
            $this->teacher->id,
            $attendance->fresh()->instructor_id,
            'The day the student actually trained still names the teacher who taught it.',
        );

        // And the new teacher may queue them from tomorrow, without any of the
        // above changing.
        Carbon::setTestNow(Carbon::parse('2026-09-15 08:00:00', self::BUSINESS_TIMEZONE));
        $this->assertContains('Ilyas CABDI', $this->offeredTo($this->otherUser));
        $this->assertNotContains('Ilyas CABDI', $this->offeredTo($this->teacherUser));
    }

    /* ================================================================
     | 13 — the rolling twelve hours
     | ================================================================ */

    /** 13.1 */
    public function test_a_student_who_has_never_trained_is_eligible(): void
    {
        $verdict = $this->eligibility()->canQueueStudent($this->teacher, $this->mine);

        $this->assertTrue($verdict->eligible);
        $this->assertNull($verdict->eligibleAt);
        $this->assertNull($this->eligibility()->eligibleAt($this->mine->id));
    }

    /** 13.2 + 13.3 + 13.4 — 11h59m no, 12h00m yes, 12h01m yes. */
    public function test_the_cooldown_lifts_exactly_twelve_hours_after_finishing(): void
    {
        $this->completeOneSession($this->mine);

        $finishedAt = TrainingEvaluation::firstOrFail()->evaluated_at;
        $this->assertSame(
            $finishedAt->copy()->addHours(12)->toDateTimeString(),
            $this->eligibility()->eligibleAt($this->mine->id)->toDateTimeString(),
        );

        Carbon::setTestNow($finishedAt->copy()->addHours(11)->addMinutes(59));
        $blocked = $this->eligibility()->canQueueStudent($this->teacher, $this->mine);
        $this->assertFalse($blocked->eligible, '11h59m is not twelve hours.');
        $this->assertSame(QueueEligibility::COOLING_DOWN, $blocked->code);
        $this->assertSame(60, $blocked->remainingSeconds());

        Carbon::setTestNow($finishedAt->copy()->addHours(12));
        $this->assertTrue(
            $this->eligibility()->canQueueStudent($this->teacher, $this->mine)->eligible,
            'Exactly twelve hours is eligible.',
        );

        Carbon::setTestNow($finishedAt->copy()->addHours(12)->addMinute());
        $this->assertTrue($this->eligibility()->canQueueStudent($this->teacher, $this->mine)->eligible);
    }

    /**
     * 13.5 + 13.6 — the brief's second example. Finishing at 23:00 means 11:00
     * the next morning, so midnight passing is not what lets them back in.
     */
    public function test_a_cooldown_crosses_midnight_and_midnight_does_not_reset_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 23:00:00', self::BUSINESS_TIMEZONE));
        $this->completeOneSession($this->mine);

        // One minute past midnight: a new calendar day, the same cooldown.
        Carbon::setTestNow(Carbon::parse('2026-09-15 00:01:00', self::BUSINESS_TIMEZONE));
        $this->assertSame('2026-09-15', today()->toDateString(), 'A new day has begun.');
        $justAfterMidnight = $this->eligibility()->canQueueStudent($this->teacher, $this->mine);
        $this->assertFalse($justAfterMidnight->eligible, 'Midnight is not a reset.');
        $this->assertSame(QueueEligibility::COOLING_DOWN, $justAfterMidnight->code);

        // 10:59 the next morning — still one minute short.
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:59:00', self::BUSINESS_TIMEZONE));
        $this->assertFalse($this->eligibility()->canQueueStudent($this->teacher, $this->mine)->eligible);

        // 11:00 — twelve hours to the minute, in the school's own time.
        Carbon::setTestNow(Carbon::parse('2026-09-15 11:00:00', self::BUSINESS_TIMEZONE));
        $this->assertTrue($this->eligibility()->canQueueStudent($this->teacher, $this->mine)->eligible);
    }

    /** 13.7 — the message a teacher reads is the school's wall clock. */
    public function test_the_refusal_names_the_local_time_the_student_comes_back(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00', self::BUSINESS_TIMEZONE));
        $this->completeOneSession($this->mine);

        $this->addAs($this->teacherUser, $this->mine)
            ->assertSessionHasErrors(['student_id' => 'This student can be added again at 20:00.']);

        $this->assertSame(1, TrainingQueueEntry::count(), 'The refusal wrote nothing.');

        $verdict = $this->eligibility()->canQueueStudent($this->teacher, $this->mine);
        $this->assertSame('20:00', $verdict->availableAt());
        $this->assertSame('12h 0m', $verdict->remainingLabel());
    }

    /** 13.8 — a refused re-queue leaves the finished cycle exactly as it was. */
    public function test_a_refusal_changes_nothing_about_the_completed_history(): void
    {
        $session = $this->completeOneSession($this->mine);
        $before = TrainingQueueEntry::firstOrFail()->getAttributes();
        $evaluationCount = TrainingEvaluation::count();

        Carbon::setTestNow(now()->addHours(3));
        $this->addAs($this->teacherUser, $this->mine)->assertSessionHasErrors(['student_id']);

        $this->assertSame($before, TrainingQueueEntry::firstOrFail()->getAttributes());
        $this->assertSame($evaluationCount, TrainingEvaluation::count());
        $this->assertDatabaseHas('training_sessions', ['id' => $session->id, 'status' => TrainingSession::COMPLETED]);
    }

    /**
     * The cooldown follows the student, not the teacher: being handed to
     * somebody else is not a way to train twice in an hour.
     */
    public function test_a_transfer_does_not_clear_the_cooldown(): void
    {
        $this->completeOneSession($this->mine);

        app(StudentTransferService::class)->transfer($this->mine, $this->other, 'Cover', $this->admin);

        Carbon::setTestNow(now()->addHours(2));

        $this->addAs($this->otherUser, $this->mine->fresh())
            ->assertSessionHasErrors(['student_id' => 'This student can be added again at 20:00.']);
    }

    /** A student marked absent never trained, so nothing is counting down. */
    public function test_an_absent_student_is_not_put_in_cooldown(): void
    {
        Setting::put('training_auto_start_next', '0');

        $this->queue()->add($this->mine, $this->admin);
        $session = $this->sessions()->startNext($this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);
        $this->sessions()->end($session, $this->teacherUser);
        $this->sessions()->evaluate($session->fresh(), ['attendance_status' => 'absent'], $this->teacherUser);

        Carbon::setTestNow(now()->addMinutes(5));

        $this->assertNull($this->eligibility()->eligibleAt($this->mine->id));
        $this->assertTrue($this->eligibility()->canQueueStudent($this->teacher, $this->mine)->eligible);
    }

    /** 15 — the whole completion cycle, end to end. */
    public function test_after_completion_the_student_leaves_the_line_and_comes_back_only_by_hand(): void
    {
        $this->completeOneSession($this->mine);

        $board = app(TrainingBoardService::class)->board($this->teacherUser);
        $this->assertSame([], collect($board['queue'])->pluck('student')->all());
        $this->assertNull($board['current']);
        $this->assertSame(1, $board['stats']['completed_today']);

        // Twelve hours on, still nobody in the line — availability is not
        // enrolment.
        Carbon::setTestNow(now()->addHours(12));
        $this->assertSame(1, TrainingQueueEntry::count());
        $this->assertSame([], collect(app(TrainingBoardService::class)->board($this->teacherUser)['queue'])->pluck('student')->all());

        // But now the dialog offers them, and the teacher's click is what counts.
        $this->assertTrue($this->eligibility()->canQueueStudent($this->teacher, $this->mine)->eligible);
        $this->addAs($this->teacherUser, $this->mine)->assertSessionHasNoErrors();

        $this->assertSame(2, TrainingQueueEntry::count());
        $this->assertSame(['Ilyas CABDI'], collect(app(TrainingBoardService::class)->board($this->teacherUser)['queue'])->pluck('student')->all());
    }

    /* ================================================================
     | 14 — the queue is manual
     | ================================================================ */

    /** 14.1 + 14.2 */
    public function test_opening_and_refreshing_the_console_creates_no_queue_entries(): void
    {
        foreach (range(1, 3) as $_) {
            $this->actingAs($this->teacherUser)->get(route('instructor.training.index'))->assertOk();
        }

        $this->assertSame(0, TrainingQueueEntry::count());
    }

    /** 14.3 — the polling endpoint every console hits every few seconds. */
    public function test_polling_the_board_creates_no_queue_entries(): void
    {
        foreach (range(1, 5) as $_) {
            $this->actingAs($this->teacherUser)->get(route('instructor.training.board'))->assertOk();
        }

        // The admin's whole-floor board reads every teacher's line, and used to
        // materialise all of them.
        $this->actingAs($this->admin)->get(route('admin.training.board'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.training.index'))->assertOk();
        app(TrainingBoardService::class)->board(null);
        app(TrainingBoardService::class)->teacherBoards();

        $this->assertSame(0, TrainingQueueEntry::count());
    }

    /** 14.3b — nor does the dashboard, or a day rolling over. */
    public function test_no_read_of_any_kind_enrols_a_student(): void
    {
        $this->actingAs($this->teacherUser)->get(route('instructor.dashboard'))->assertOk();

        Carbon::setTestNow(now()->addDay());

        $this->actingAs($this->teacherUser)->get(route('instructor.training.index'))->assertOk();
        $this->actingAs($this->teacherUser)->get(route('instructor.training.board'))->assertOk();

        $this->assertSame(0, TrainingQueueEntry::count());
        $this->assertFalse(
            method_exists(TrainingQueueService::class, 'ensureQueuedFor'),
            'The automatic enrolment method must not come back.',
        );
    }

    /** 14.4 */
    public function test_add_student_creates_exactly_one_entry(): void
    {
        $this->addAs($this->teacherUser, $this->mine)->assertSessionHasNoErrors();

        $this->assertSame(1, TrainingQueueEntry::count());

        $entry = TrainingQueueEntry::firstOrFail();
        $this->assertSame($this->mine->id, $entry->student_id);
        $this->assertSame(TrainingQueueEntry::WAITING, $entry->status);
        $this->assertSame('2026-09-14', $entry->queue_date->toDateString());
        $this->assertSame(0, TrainingSession::count(), 'Adding is not starting.');
    }

    /** 14.5 — a double click, as the browser actually sends it. */
    public function test_a_double_click_cannot_create_two_waiting_entries(): void
    {
        $this->addAs($this->teacherUser, $this->mine)->assertSessionHasNoErrors();
        $this->addAs($this->teacherUser, $this->mine)->assertSessionHasErrors(['student_id']);

        $this->assertSame(1, TrainingQueueEntry::count());
    }

    /**
     * 14.5b — and with the service bypassed entirely, the database still
     * refuses. NULLs do not collide, so the finished cycles behind it are fine.
     */
    public function test_the_database_refuses_a_second_open_entry_for_the_same_day(): void
    {
        $this->queue()->add($this->mine, $this->admin);

        try {
            TrainingQueueEntry::create([
                'student_id' => $this->mine->id,
                'queue_date' => today()->toDateString(),
                'position' => 2,
                'status' => TrainingQueueEntry::WAITING,
                'joined_at' => now(),
            ]);
            $this->fail('The database allowed two open queue entries for one student on one day.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage());
        }

        $this->assertSame(1, TrainingQueueEntry::count());
    }

    /** 14.5c — but a finished cycle no longer holds the guard. */
    public function test_a_finished_cycle_releases_the_database_guard(): void
    {
        $this->completeOneSession($this->mine);

        $this->assertNull(
            TrainingQueueEntry::firstOrFail()->active_student_id,
            'A completed cycle occupies nobody.',
        );

        Carbon::setTestNow(now()->addHours(12));
        $this->addAs($this->teacherUser, $this->mine)->assertSessionHasNoErrors();

        $entries = TrainingQueueEntry::orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertNull($entries[0]->active_student_id);
        $this->assertSame($this->mine->id, $entries[1]->active_student_id);
    }

    /** 14.6 */
    public function test_a_student_already_waiting_cannot_be_added_again(): void
    {
        $this->addAs($this->teacherUser, $this->mine);

        $this->addAs($this->teacherUser, $this->mine)
            ->assertSessionHasErrors(['student_id' => 'This student is already in the waiting queue.']);

        $this->assertSame(1, TrainingQueueEntry::count());
    }

    /** 14.7 */
    public function test_a_student_currently_training_cannot_be_added(): void
    {
        $this->addAs($this->teacherUser, $this->mine);
        $this->sessions()->startNext($this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);

        $this->addAs($this->teacherUser, $this->mine)
            ->assertSessionHasErrors(['student_id' => 'This student is currently in training.']);

        $this->assertSame(1, TrainingQueueEntry::count());
    }

    /** A student who has left the school is not offered at all. */
    public function test_an_inactive_student_is_neither_offered_nor_accepted(): void
    {
        $this->mine->forceFill(['status' => 'completed'])->save();

        $this->assertNotContains('Ilyas CABDI', $this->offeredTo($this->teacherUser));
        $this->addAs($this->teacherUser, $this->mine->fresh())->assertSessionHasErrors(['student_id']);

        $this->assertSame(0, TrainingQueueEntry::count());
    }

    /**
     * The one migration this change needs, run twice.
     *
     * It is the only thing here that touches a production table, so prove it is
     * additive and idempotent before anybody runs it there: a second pass adds
     * nothing, drops nothing and leaves every row exactly as it found it.
     */
    public function test_the_queue_cycle_migration_is_idempotent_and_keeps_every_row(): void
    {
        $this->completeOneSession($this->mine);
        Carbon::setTestNow(now()->addHours(12));
        $this->addAs($this->teacherUser, $this->mine);

        $before = TrainingQueueEntry::orderBy('id')->get()->map->getAttributes()->all();
        $this->assertCount(2, $before, 'Two cycles on one date is the case the migration exists for.');

        $migration = require database_path('migrations/2026_09_14_000100_allow_repeat_training_queue_cycles.php');
        $migration->up();
        $migration->up();

        $this->assertSame($before, TrainingQueueEntry::orderBy('id')->get()->map->getAttributes()->all());

        // And the guard is still a guarantee, not a leftover column.
        try {
            TrainingQueueEntry::create([
                'student_id' => $this->mine->id,
                'queue_date' => today()->toDateString(),
                'position' => 9,
                'status' => TrainingQueueEntry::WAITING,
                'joined_at' => now(),
            ]);
            $this->fail('The guard did not survive re-running the migration.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage());
        }
    }

    /** The dialog says when a student in cooldown comes back. */
    public function test_the_dialog_marks_a_student_in_cooldown_with_the_time_they_return(): void
    {
        $this->completeOneSession($this->mine);

        $page = $this->actingAs($this->teacherUser)->get(route('instructor.training.index'));

        $addable = $page->viewData('addable')->keyBy('full_name');
        $verdict = $addable['Ilyas CABDI']->queue_eligibility;

        $this->assertFalse($verdict->eligible);
        $this->assertSame(QueueEligibility::COOLING_DOWN, $verdict->code);
        $this->assertSame('20:00', $verdict->availableAt());

        $page->assertSee('Available at 20:00', false);
    }
}
