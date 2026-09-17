<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\Student;
use App\Models\TrainingEvaluation;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingBoardService;
use App\Services\TrainingEligibilityService;
use App\Services\TrainingQueueService;
use App\Services\TrainingSessionService;
use App\Services\TrainingStaleCycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The life of one training cycle: queued by hand, started by hand, and closed
 * by a person or by the clock — never by the software deciding for itself.
 *
 * Three rules hold it together:
 *
 *   Queueing is not training. A student may wait all morning with no session,
 *   no clock and no attendance. Only Start Training begins one.
 *
 *   One open cycle per student, school-wide. Not per teacher and not per day:
 *   yesterday's unfinished entry still blocks today.
 *
 *   Nothing stays open for ever. Twelve hours after the current unresolved
 *   state began, the cycle closes — and because no training happened, no
 *   cooldown follows it. That is a different twelve hours from the one after a
 *   session that really was completed.
 */
class TrainingCycleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacherUser;

    private User $otherUser;

    private Instructor $teacher;

    private Instructor $other;

    private Student $student;

    private Student $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-18 08:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->teacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Xasan']);
        $this->otherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Nasteexo']);
        $this->teacher = $this->makeInstructor('Xasan', $this->teacherUser);
        $this->other = $this->makeInstructor('Nasteexo', $this->otherUser);

        $this->student = $this->makeStudent('Ahmed Ali', $this->other);
        $this->second = $this->makeStudent('Maryan Ciise', $this->other);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
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

    private function stale(): TrainingStaleCycleService
    {
        return app(TrainingStaleCycleService::class);
    }

    private function addAs(User $user, Student $student)
    {
        return $this->actingAs($user)->post(route('instructor.training.queue.store'), [
            'student_id' => $student->id,
            'assigned_duration_minutes' => 30,
        ]);
    }

    private function startAs(User $user, TrainingQueueEntry $entry)
    {
        return $this->actingAs($user)->post(route('instructor.training.start'), [
            'training_queue_entry_id' => $entry->id,
            'assigned_duration_minutes' => 30,
        ]);
    }

    private function entryFor(Student $student): TrainingQueueEntry
    {
        return TrainingQueueEntry::where('student_id', $student->id)->open()->firstOrFail();
    }

    /** Asserts that closing a cycle invented nothing. */
    private function assertNothingWasFabricated(): void
    {
        $this->assertSame(0, Attendance::count(), 'No attendance may be created.');
        $this->assertSame(0, TrainingEvaluation::count(), 'No evaluation may be created.');
        $this->assertSame(0, Lesson::count(), 'No lesson may be created.');
        $this->assertSame(
            0,
            TrainingSession::where('status', TrainingSession::COMPLETED)->count(),
            'No session may be recorded as successfully completed.',
        );
    }

    /* ================================================================
     | Queue is not training  (37: 32, 33, 38, 39, 40)
     | ================================================================ */

    public function test_adding_to_the_queue_creates_no_session_and_no_clock(): void
    {
        $this->addAs($this->teacherUser, $this->student)->assertSessionHasNoErrors();

        $entry = $this->entryFor($this->student);

        $this->assertSame(TrainingQueueEntry::WAITING, $entry->status);
        $this->assertSame(0, TrainingSession::count(), 'Adding is not starting.');
        $this->assertNull($this->sessions()->activeForInstructor($this->teacher->id));
        $this->assertNull(app(TrainingBoardService::class)->board($this->teacherUser)['current']);
        $this->assertNothingWasFabricated();

        // Hours may pass, and they are still only waiting.
        Carbon::setTestNow(now()->addHours(6));
        $this->assertSame(0, TrainingSession::count());
        $this->assertSame(TrainingQueueEntry::WAITING, $entry->fresh()->status);
    }

    /** 33 + 34 + 35 — nothing a page does starts a session. */
    public function test_no_read_of_any_kind_starts_training(): void
    {
        $this->addAs($this->teacherUser, $this->student);

        foreach (range(1, 3) as $_) {
            $this->actingAs($this->teacherUser)->get(route('instructor.training.index'))->assertOk();
            $this->actingAs($this->teacherUser)->get(route('instructor.training.board'))->assertOk();
            $this->actingAs($this->teacherUser)->getJson(route('instructor.training.students.search', ['q' => 'Ahmed']))->assertOk();
        }

        $this->actingAs($this->admin)->get(route('admin.training.index'))->assertOk();
        app(TrainingBoardService::class)->board($this->teacherUser);

        $this->assertSame(0, TrainingSession::count(), 'Being first in the queue starts nobody.');
        $this->assertSame(1, TrainingQueueEntry::count(), 'And polling queues nobody.');
        $this->assertSame(TrainingQueueEntry::WAITING, $this->entryFor($this->student)->status);
    }

    /** 38 + 39 + 40 — only the button starts it, and started_at is that moment. */
    public function test_only_start_training_begins_a_session(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        $entry = $this->entryFor($this->student);

        Carbon::setTestNow(Carbon::parse('2026-09-18 09:30:00'));

        $this->startAs($this->teacherUser, $entry)->assertSessionHasNoErrors();

        $session = TrainingSession::firstOrFail();

        $this->assertSame(TrainingSession::IN_PROGRESS, $session->status);
        $this->assertSame($this->teacher->id, $session->instructor_id);
        $this->assertSame('2026-09-18 09:30:00', $session->started_at->toDateTimeString());
        $this->assertSame('2026-09-18 10:00:00', $session->expected_end_at->toDateTimeString());
        $this->assertSame(TrainingQueueEntry::TRAINING_IN_PROGRESS, $entry->fresh()->status);
    }

    /** 41 + 42 — reloading resumes; double-clicking does not double up. */
    public function test_reloading_resumes_and_a_double_click_starts_one_session(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        $entry = $this->entryFor($this->student);

        $this->startAs($this->teacherUser, $entry)->assertSessionHasNoErrors();
        $session = TrainingSession::firstOrFail();

        // The second click of a double click.
        $this->startAs($this->teacherUser, $entry->fresh());

        $this->assertSame(1, TrainingSession::count(), 'One press, one session.');

        Carbon::setTestNow(now()->addMinutes(10));

        $board = app(TrainingBoardService::class)->board($this->teacherUser);

        $this->assertSame($session->id, $board['current']['id']);
        $this->assertSame(1200, $board['current']['remaining_seconds'], 'The clock resumed, it did not restart.');
        $this->assertSame(
            $session->started_at->toDateTimeString(),
            $session->fresh()->started_at->toDateTimeString(),
            'started_at is never reset.',
        );
        $this->assertSame(1, TrainingSession::count());
    }

    /** 36 + 37 — finishing one student leaves the next waiting. */
    public function test_finishing_one_student_never_starts_the_next(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        $this->startAs($this->teacherUser, $this->entryFor($this->student));
        $session = TrainingSession::firstOrFail();

        Carbon::setTestNow(now()->addMinutes(5));
        $this->addAs($this->teacherUser, $this->second);

        $this->sessions()->end($session, $this->teacherUser);
        $this->sessions()->evaluate($session->fresh(), [
            'attendance_status' => 'present', 'evaluation' => 'good',
        ], $this->teacherUser);

        // Maryan is first in the line and still only waiting.
        $this->assertSame(1, TrainingSession::count());
        $this->assertSame(TrainingQueueEntry::WAITING, $this->entryFor($this->second)->status);
        $this->assertNull($this->sessions()->activeForInstructor($this->teacher->id));

        // Even after every kind of refresh.
        $this->actingAs($this->teacherUser)->get(route('instructor.training.board'))->assertOk();
        $this->assertSame(1, TrainingSession::count());
    }

    /* ================================================================
     | One open cycle, school-wide  (38: 43-49)
     | ================================================================ */

    public function test_a_waiting_student_cannot_be_queued_by_another_instructor(): void
    {
        $this->addAs($this->teacherUser, $this->student)->assertSessionHasNoErrors();

        $this->addAs($this->otherUser, $this->student)
            ->assertSessionHasErrors(['student_id' => 'This student is already in the waiting queue.']);

        $this->assertSame(1, TrainingQueueEntry::count());
        $this->assertTrue(app(TrainingEligibilityService::class)->hasOpenCycle($this->student));
    }

    /** 44 — every open status blocks, not just waiting. */
    public function test_each_open_status_blocks_a_second_cycle(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        $this->startAs($this->teacherUser, $this->entryFor($this->student));

        // training_in_progress
        $this->assertSame(TrainingQueueEntry::TRAINING_IN_PROGRESS, $this->entryFor($this->student)->status);
        $this->addAs($this->otherUser, $this->student)->assertSessionHasErrors(['student_id']);

        // attendance_pending
        $this->sessions()->end(TrainingSession::firstOrFail(), $this->teacherUser);
        $this->assertSame(TrainingQueueEntry::ATTENDANCE_PENDING, $this->entryFor($this->student)->status);
        $this->addAs($this->otherUser, $this->student)->assertSessionHasErrors(['student_id']);

        $this->assertSame(1, TrainingQueueEntry::count());
    }

    /** 45 — yesterday's open cycle is still open today. */
    public function test_yesterdays_open_cycle_blocks_today(): void
    {
        $this->addAs($this->teacherUser, $this->student)->assertSessionHasNoErrors();

        // Eleven hours later: a new-ish day, and still inside the twelve.
        Carbon::setTestNow(now()->addHours(11));

        $this->addAs($this->otherUser, $this->student)
            ->assertSessionHasErrors(['student_id' => 'This student is already in the waiting queue.']);

        $this->assertSame(1, TrainingQueueEntry::count());
    }

    /** 47 + 48 — a finished or cancelled cycle is not an open one. */
    public function test_terminal_cycles_do_not_block(): void
    {
        foreach ([TrainingQueueEntry::COMPLETED, TrainingQueueEntry::CANCELLED] as $status) {
            TrainingQueueEntry::create([
                'student_id' => $this->student->id,
                'queue_date' => today()->copy()->subDays(3)->toDateString(),
                'position' => 1,
                'status' => $status,
                'joined_at' => now()->copy()->subDays(3),
            ]);
        }

        $this->assertFalse(app(TrainingEligibilityService::class)->hasOpenCycle($this->student));
        $this->addAs($this->teacherUser, $this->student)->assertSessionHasNoErrors();
    }

    /** 49 — the database refuses a second open cycle, whatever the date. */
    public function test_the_database_refuses_a_second_open_cycle_on_any_date(): void
    {
        $this->addAs($this->teacherUser, $this->student);

        try {
            TrainingQueueEntry::create([
                'student_id' => $this->student->id,
                // A different day entirely — which used to be allowed.
                'queue_date' => today()->copy()->addDay()->toDateString(),
                'position' => 2,
                'status' => TrainingQueueEntry::WAITING,
                'joined_at' => now(),
            ]);
            $this->fail('The database allowed two open cycles for one student.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage());
        }

        $this->assertSame(1, TrainingQueueEntry::count());
    }

    /* ================================================================
     | Twelve-hour stale expiry  (39: 50-69)
     | ================================================================ */

    /** 50 + 51 — under twelve hours it stands; at twelve it goes. */
    public function test_a_waiting_cycle_expires_at_twelve_hours(): void
    {
        $this->addAs($this->teacherUser, $this->student);

        Carbon::setTestNow(now()->addHours(11)->addMinutes(59));
        $this->assertSame(0, $this->stale()->expireStale(), 'Eleven fifty-nine is not twelve hours.');
        $this->assertTrue($this->entryFor($this->student)->isOpen());

        Carbon::setTestNow(now()->addMinute());
        $this->assertSame(1, $this->stale()->expireStale());

        $entry = TrainingQueueEntry::firstOrFail();

        // 58 + 71 + 72 — closed, not deleted, and it says why.
        $this->assertSame(TrainingQueueEntry::CANCELLED, $entry->status);
        $this->assertSame('Auto-expired after 12 hours', $entry->close_reason);
        $this->assertNotNull($entry->closed_at);
        $this->assertNull($entry->closed_by, 'The clock closed it, not a person.');
        $this->assertSame(1, TrainingQueueEntry::count());
        $this->assertNotNull($entry->joined_at, 'The history of when they joined is kept.');

        // 59-62
        $this->assertNothingWasFabricated();
    }

    /** 57 — a pending evaluation is measured from when training ENDED. */
    public function test_attendance_pending_expires_twelve_hours_after_training_ended(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        $this->startAs($this->teacherUser, $this->entryFor($this->student));

        $session = TrainingSession::firstOrFail();

        // The session ran for half an hour, three hours ago.
        Carbon::setTestNow(now()->addMinutes(30));
        $this->sessions()->end($session, $this->teacherUser);
        $endedAt = $session->fresh()->ended_at;

        $this->assertSame(TrainingQueueEntry::ATTENDANCE_PENDING, $this->entryFor($this->student)->status);

        // Eleven hours after it ended: still the teacher's to finish.
        Carbon::setTestNow($endedAt->copy()->addHours(11));
        $this->assertSame(0, $this->stale()->expireStale());

        // Twelve hours after it ENDED — not after it started, and not after
        // the student joined the queue this morning.
        Carbon::setTestNow($endedAt->copy()->addHours(12));
        $this->assertSame(1, $this->stale()->expireStale());

        $entry = TrainingQueueEntry::firstOrFail();
        $this->assertSame(TrainingQueueEntry::CANCELLED, $entry->status);
        $this->assertSame(
            'Auto-expired: evaluation/attendance not completed within 12 hours',
            $entry->close_reason,
        );

        // The session itself is preserved, with its real timestamps.
        $session->refresh();
        $this->assertSame(TrainingSession::CANCELLED, $session->status);
        $this->assertNotNull($session->started_at);
        $this->assertNotNull($session->ended_at);
        $this->assertSame($endedAt->toDateTimeString(), $session->ended_at->toDateTimeString());

        // Nothing was invented to close it.
        $this->assertSame(0, Attendance::count());
        $this->assertSame(0, TrainingEvaluation::count());
        $this->assertSame(0, TrainingSession::where('status', TrainingSession::COMPLETED)->count());
    }

    /** A session still running when its twelve hours are up is cancelled, not completed. */
    public function test_a_session_left_running_expires_from_when_it_started(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        $this->startAs($this->teacherUser, $this->entryFor($this->student));

        $session = TrainingSession::firstOrFail();

        Carbon::setTestNow($session->started_at->copy()->addHours(12));
        $this->assertGreaterThanOrEqual(1, $this->stale()->expireStale());

        $session->refresh();

        $this->assertSame(TrainingSession::CANCELLED, $session->status);
        $this->assertSame(TrainingQueueEntry::CANCELLED, TrainingQueueEntry::firstOrFail()->status);
        $this->assertNothingWasFabricated();
        $this->assertNull($this->sessions()->activeForInstructor($this->teacher->id), 'The teacher is free again.');
    }

    /** 63 + 64 — the two twelve-hour rules are different rules. */
    public function test_an_expired_wait_starts_no_cooldown_but_a_real_session_does(): void
    {
        // A cycle nobody ever trained.
        $this->addAs($this->teacherUser, $this->student);
        Carbon::setTestNow(now()->addHours(12));
        $this->stale()->expireStale();

        // No training happened, so nothing is counting down: they may be
        // queued again at once.
        $this->assertNull(app(TrainingEligibilityService::class)->eligibleAt($this->student->id));
        $this->addAs($this->otherUser, $this->student)->assertSessionHasNoErrors();

        // Now a session that really was completed.
        $this->startAs($this->otherUser, $this->entryFor($this->student));
        $session = TrainingSession::where('status', TrainingSession::IN_PROGRESS)->firstOrFail();
        $this->sessions()->end($session, $this->otherUser);
        $this->sessions()->evaluate($session->fresh(), [
            'attendance_status' => 'present', 'evaluation' => 'good',
        ], $this->otherUser);

        // That one does start the cooldown.
        $this->assertNotNull(app(TrainingEligibilityService::class)->eligibleAt($this->student->id));
        $this->addAs($this->teacherUser, $this->student)->assertInvalid(['student_id' => 'can be added again at']);
    }

    /** 65 + 66 — cleanup happens at request time, not only on cron. */
    public function test_the_console_expires_stale_cycles_before_judging_eligibility(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        Carbon::setTestNow(now()->addHours(13));

        // A search is enough to release them.
        $found = $this->actingAs($this->otherUser)
            ->getJson(route('instructor.training.students.search', ['q' => 'Ahmed']))
            ->assertOk()
            ->json('results');

        $this->assertTrue($found[0]['eligible'], 'A thirteen-hour-old wait must not still block them.');
        $this->assertSame(TrainingQueueEntry::CANCELLED, TrainingQueueEntry::firstOrFail()->status);

        // And so is an Add, which re-checks after cleaning up.
        $this->addAs($this->otherUser, $this->student)->assertSessionHasNoErrors();
        $this->assertSame(2, TrainingQueueEntry::count());
    }

    /** 68 + 69 — the command is idempotent, and cleanup never creates work. */
    public function test_the_cleanup_command_is_idempotent_and_creates_nothing(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        Carbon::setTestNow(now()->addHours(12));

        $this->artisan('training:expire-stale')
            ->expectsOutputToContain('Closed 1 training cycle')
            ->assertSuccessful();

        $this->artisan('training:expire-stale')
            ->expectsOutputToContain('No training cycles have been open longer')
            ->assertSuccessful();

        $this->assertSame(1, TrainingQueueEntry::count(), 'Cleanup never adds a queue entry.');
        $this->assertSame(0, TrainingSession::count(), 'Cleanup never starts a session.');
        $this->assertNothingWasFabricated();
    }

    /* ================================================================
     | Manual remove  (40: 70-84)
     | ================================================================ */

    public function test_an_instructor_removes_their_own_waiting_student(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        $entry = $this->entryFor($this->student);

        $this->actingAs($this->teacherUser)
            ->delete(route('instructor.training.queue.remove', $entry))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $entry->refresh();

        // 71 + 72 + 73 + 74
        $this->assertSame(TrainingQueueEntry::CANCELLED, $entry->status);
        $this->assertSame('Removed from queue by instructor', $entry->close_reason);
        $this->assertNotNull($entry->closed_at);
        $this->assertSame($this->teacherUser->id, $entry->closed_by);
        $this->assertSame(1, TrainingQueueEntry::count(), 'The row is kept.');

        // 75 — gone from the active queue.
        $this->assertSame([], collect(app(TrainingBoardService::class)->board($this->teacherUser)['queue'])->pluck('student')->all());

        // 76 + 80 — queueable again at once, with no cooldown invented.
        $this->assertNull(app(TrainingEligibilityService::class)->eligibleAt($this->student->id));
        $this->addAs($this->otherUser, $this->student)->assertSessionHasNoErrors();

        // 77 + 78 + 79
        $this->assertNothingWasFabricated();
    }

    /** 81 + 82 — a second click, and a click racing the expiry, are both safe. */
    public function test_removing_twice_is_safe(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        $entry = $this->entryFor($this->student);

        $this->actingAs($this->teacherUser)->delete(route('instructor.training.queue.remove', $entry))->assertRedirect();
        $this->actingAs($this->teacherUser)->delete(route('instructor.training.queue.remove', $entry->fresh()))->assertForbidden();

        $this->assertSame(1, TrainingQueueEntry::count());
        $this->assertSame($this->teacherUser->id, $entry->fresh()->closed_by, 'The first close stands.');

        // The service itself is idempotent, whichever path reaches it second.
        $this->assertFalse($this->queue()->closeCycle($entry->fresh(), 'Auto-expired after 12 hours'));
        $this->assertSame('Removed from queue by instructor', $entry->fresh()->close_reason);
    }

    /** 83 — another instructor's active entry is not theirs to cancel. */
    public function test_an_unrelated_instructor_cannot_remove_someone_elses_queue_entry(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        $entry = $this->entryFor($this->student);

        $this->actingAs($this->otherUser)
            ->delete(route('instructor.training.queue.remove', $entry))
            ->assertForbidden();

        $this->assertTrue($entry->fresh()->isOpen(), 'Being allowed to train anyone is not being allowed to cancel their work.');

        // The admin may.
        $this->actingAs($this->admin)
            ->delete(route('admin.training.queue.destroy', $entry))
            ->assertRedirect();

        $this->assertSame(TrainingQueueEntry::CANCELLED, $entry->fresh()->status);
    }

    /** 84 — once training has started, the simple Remove is refused. */
    public function test_a_started_session_is_not_closed_by_the_waiting_remove(): void
    {
        $this->addAs($this->teacherUser, $this->student);
        $entry = $this->entryFor($this->student);
        $this->startAs($this->teacherUser, $entry);

        $this->actingAs($this->teacherUser)
            ->delete(route('instructor.training.queue.remove', $entry->fresh()))
            ->assertForbidden();

        $this->assertSame(TrainingQueueEntry::TRAINING_IN_PROGRESS, $entry->fresh()->status);
        $this->assertSame(TrainingSession::IN_PROGRESS, TrainingSession::firstOrFail()->status);

        // The session's own cancellation is the way, and it keeps the row.
        $this->actingAs($this->teacherUser)
            ->post(route('instructor.training.cancel', TrainingSession::firstOrFail()), ['reason' => 'Student left'])
            ->assertRedirect();

        $this->assertSame(TrainingSession::CANCELLED, TrainingSession::firstOrFail()->status);
        $this->assertNotNull(TrainingSession::firstOrFail()->started_at);
        $this->assertSame(1, TrainingSession::count());
    }

    /** The board tells each console which entries it may close. */
    public function test_only_the_holding_console_is_offered_remove(): void
    {
        $this->addAs($this->teacherUser, $this->student);

        $mine = collect(app(TrainingBoardService::class)->board($this->teacherUser)['queue'])->first();
        $theirs = collect(app(TrainingBoardService::class)->board($this->otherUser)['queue'])->first();

        $this->assertTrue($mine['can_remove']);
        $this->assertNull($theirs, 'The entry is not on the other console at all.');
    }
}
