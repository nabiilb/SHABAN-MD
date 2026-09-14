<?php

namespace Tests\Feature;

use App\Models\Instructor;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Student;
use App\Models\TrainingEvaluation;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingBoardService;
use App\Services\TrainingQueueService;
use App\Services\TrainingSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The console and the claim must answer "is this teacher busy?" the same way.
 *
 * They did not: the console looked for a live session *started today*, and
 * starting looked for a live session on *any* day. A session left running
 * overnight therefore vanished from the page while still refusing every new
 * student — "NO TRAINING IN PROGRESS" on screen and "You already have a
 * training session in progress." on the button. Both now ask
 * TrainingSessionService::activeForInstructor().
 */
class TrainingConsoleStateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacherUser;

    private User $otherUser;

    private Instructor $teacher;

    private Instructor $other;

    /** @var array<string, Student> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-14 12:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->teacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Cabdi']);
        $this->otherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Xasan']);
        $this->teacher = $this->makeInstructor('Cabdi', $this->teacherUser);
        $this->other = $this->makeInstructor('Xasan', $this->otherUser);

        foreach (['Ali', 'Amina', 'Hodan'] as $name) {
            $this->students[$name] = $this->makeStudent($name, $this->teacher);
        }

        $this->students['Yahye'] = $this->makeStudent('Yahye', $this->other);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sessions(): TrainingSessionService
    {
        return app(TrainingSessionService::class);
    }

    private function board(User $user): array
    {
        return app(TrainingBoardService::class)->board($user);
    }

    private function queueAll(): void
    {
        foreach ($this->students as $student) {
            app(TrainingQueueService::class)->add($student, $this->admin);
            Carbon::setTestNow(now()->addSecond());
        }
    }

    /**
     * The student's open queue entry, whatever day it was opened on — nothing
     * enrols them again when the clock passes midnight, so a test that crosses
     * into the next day is still looking for yesterday's row.
     */
    private function entryFor(Student $student): TrainingQueueEntry
    {
        return TrainingQueueEntry::where('student_id', $student->id)
            ->inLine()
            ->orderBy('queue_date')
            ->firstOrFail();
    }

    /* 1 --------------------------------------------------------------- */
    public function test_a_teacher_with_nothing_running_sees_no_training_in_progress(): void
    {
        $this->queueAll();

        $board = $this->board($this->teacherUser);

        $this->assertNull($board['current']);
        $this->assertNull($this->sessions()->activeForInstructor($this->teacher->id));
        $this->actingAs($this->teacherUser)->get(route('instructor.training.index'))
            ->assertOk()->assertSee('No training in progress');
    }

    /* 2 --------------------------------------------------------------- */
    public function test_starting_creates_exactly_one_active_session(): void
    {
        $this->queueAll();

        $session = $this->sessions()->start($this->entryFor($this->students['Ali']), $this->teacher, $this->teacherUser, [
            'assigned_duration_minutes' => 30,
        ]);

        $this->assertSame(1, TrainingSession::where('instructor_id', $this->teacher->id)->live()->count());
        $this->assertSame($session->id, $this->sessions()->activeForInstructor($this->teacher->id)->id);
        $this->assertSame(TrainingSession::IN_PROGRESS, $session->status);
    }

    /* 3 + 10 — refresh, logout, login: the same session, the same clock. */
    public function test_the_session_survives_a_refresh_and_a_new_login(): void
    {
        $this->queueAll();
        $session = $this->sessions()->start($this->entryFor($this->students['Ali']), $this->teacher, $this->teacherUser, [
            'assigned_duration_minutes' => 30,
        ]);

        Carbon::setTestNow(now()->addMinutes(10));

        foreach (range(1, 3) as $_) {
            $board = $this->board($this->teacherUser);

            $this->assertSame($session->id, $board['current']['id']);
            $this->assertSame(1200, $board['current']['remaining_seconds']);
        }

        $this->post('/logout');
        $this->actingAs($this->teacherUser->fresh());

        $board = $this->board($this->teacherUser);

        $this->assertSame($session->id, $board['current']['id']);
        $this->assertSame(1200, $board['current']['remaining_seconds']);
        $this->assertSame(1, TrainingSession::count());
    }

    /* 4 + 5 — the page and the button agree, including across midnight. */
    public function test_a_session_left_from_yesterday_is_shown_and_still_blocks(): void
    {
        $this->queueAll();
        $session = $this->sessions()->start($this->entryFor($this->students['Ali']), $this->teacher, $this->teacherUser, [
            'assigned_duration_minutes' => 30,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-15 00:37:00'));

        $board = $this->board($this->teacherUser);

        // The page no longer pretends nothing is running.
        $this->assertNotNull($board['current'], 'A session left from yesterday must still be on the console.');
        $this->assertSame($session->id, $board['current']['id']);
        $this->assertTrue($board['current']['carried_over']);
        $this->assertSame('14/09/2026', $board['current']['started_on']);

        // And the claim refuses for the reason the page is now showing.
        try {
            $this->sessions()->start($this->entryFor($this->students['Amina']), $this->teacher, $this->teacherUser, [
                'assigned_duration_minutes' => 30,
            ]);
            $this->fail('A second session should not have been allowed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already have a training session in progress', $e->getMessage());
            $this->assertStringContainsString('Ali', $e->getMessage());
        }

        $this->assertSame(1, TrainingSession::where('instructor_id', $this->teacher->id)->count());
    }

    /** Whatever the console shows, the claim agrees — asserted as one rule. */
    public function test_the_console_and_the_claim_never_disagree(): void
    {
        $this->queueAll();

        foreach ([
            'nothing running' => null,
            'running' => fn () => $this->sessions()->start($this->entryFor($this->students['Ali']), $this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]),
            'awaiting evaluation' => fn () => $this->sessions()->end($this->sessions()->activeForInstructor($this->teacher->id), $this->teacherUser),
            'the next day' => fn () => Carbon::setTestNow(now()->addDay()),
        ] as $label => $arrange) {
            if ($arrange) {
                $arrange();
            }

            $shown = $this->board($this->teacherUser)['current'];
            $canonical = $this->sessions()->activeForInstructor($this->teacher->id);

            $this->assertSame(
                $canonical?->id,
                $shown['id'] ?? null,
                "The console and activeForInstructor() disagree when {$label}.",
            );
        }
    }

    /* 6 --------------------------------------------------------------- */
    public function test_one_teachers_session_does_not_block_another(): void
    {
        $this->queueAll();

        $this->sessions()->start($this->entryFor($this->students['Ali']), $this->teacher, $this->teacherUser, [
            'assigned_duration_minutes' => 30,
        ]);

        $other = $this->sessions()->start($this->entryFor($this->students['Yahye']), $this->other, $this->otherUser, [
            'assigned_duration_minutes' => 30,
        ]);

        $this->assertNotNull($other);

        // Each console shows its own teacher's session and no one else's.
        $this->assertSame('Ali', $this->board($this->teacherUser)['current']['student']);
        $this->assertSame('Yahye', $this->board($this->otherUser)['current']['student']);
        $this->assertSame(2, TrainingSession::live()->count());
    }

    /* 7 + 8 + 11 + 13 — finishing, evaluating, and what lands where. */
    public function test_finishing_and_evaluating_moves_the_session_and_the_queue_together(): void
    {
        Setting::put('training_auto_start_next', '0');
        $this->queueAll();

        $session = $this->sessions()->start($this->entryFor($this->students['Ali']), $this->teacher, $this->teacherUser, [
            'assigned_duration_minutes' => 30,
        ]);

        $this->assertSame(TrainingQueueEntry::TRAINING_IN_PROGRESS, $session->queueEntry->fresh()->status);

        $this->sessions()->end($session, $this->teacherUser);

        $this->assertSame(TrainingSession::ATTENDANCE_PENDING, $session->fresh()->status);
        $this->assertSame(TrainingQueueEntry::ATTENDANCE_PENDING, $session->queueEntry->fresh()->status);
        $this->assertTrue($this->board($this->teacherUser)['needs_evaluation']);

        $evaluation = $this->sessions()->evaluate($session->fresh(), [
            'attendance_status' => 'present',
            'evaluation' => 'good',
            'comment' => 'Steady.',
        ], $this->teacherUser);

        $this->assertSame(TrainingSession::COMPLETED, $session->fresh()->status);
        $this->assertSame(TrainingQueueEntry::COMPLETED, $session->queueEntry->fresh()->status);
        $this->assertSame($session->id, $evaluation->training_session_id);
        $this->assertSame($this->students['Ali']->id, $evaluation->student_id);
        $this->assertSame($this->teacher->id, $evaluation->instructor_id);
        $this->assertSame(1, TrainingEvaluation::count());

        // 8 — and it is what Completed Today lists, for this teacher alone.
        $completed = app(TrainingBoardService::class)->completedToday($this->teacher->id);
        $this->assertSame([$session->id], $completed->pluck('id')->all());
        $this->assertSame([], app(TrainingBoardService::class)->completedToday($this->other->id)->pluck('id')->all());

        // The console is free again.
        $this->assertNull($this->board($this->teacherUser)['current']);
    }

    /* 9 + 15 — history is kept. */
    public function test_yesterdays_completed_session_is_kept_and_does_not_block(): void
    {
        // Auto-advance off, so the only session in play is the one being
        // followed through to completion.
        Setting::put('training_auto_start_next', '0');
        $this->queueAll();
        $session = $this->sessions()->start($this->entryFor($this->students['Ali']), $this->teacher, $this->teacherUser, [
            'assigned_duration_minutes' => 30,
        ]);
        $this->sessions()->end($session, $this->teacherUser);
        $this->sessions()->evaluate($session->fresh(), ['attendance_status' => 'present', 'evaluation' => 'good'], $this->teacherUser);

        Carbon::setTestNow(now()->addDay());

        $this->assertDatabaseHas('training_sessions', ['id' => $session->id, 'status' => TrainingSession::COMPLETED, 'deleted_at' => null]);
        $this->assertSame(1, TrainingEvaluation::count());

        // Not on today's Completed Today, but still in the table.
        $this->assertSame([], app(TrainingBoardService::class)->completedToday($this->teacher->id)->pluck('id')->all());
        $this->assertNull($this->sessions()->activeForInstructor($this->teacher->id));
    }

    /* 14 — cancelling a stale session keeps the row and frees the teacher. */
    public function test_cancelling_a_stale_session_keeps_the_row_and_returns_the_student(): void
    {
        $this->queueAll();
        $session = $this->sessions()->start($this->entryFor($this->students['Ali']), $this->teacher, $this->teacherUser, [
            'assigned_duration_minutes' => 30,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-15 00:37:00'));
        $sessionsBefore = TrainingSession::count();

        $this->actingAs($this->teacherUser)
            ->post(route('instructor.training.cancel', $session), ['reason' => 'Left open overnight'])
            ->assertRedirect();

        $cancelled = $session->fresh();

        $this->assertSame(TrainingSession::CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->ended_at);
        $this->assertStringContainsString('Left open overnight', $cancelled->notes);
        $this->assertNull($cancelled->deleted_at);
        $this->assertSame($sessionsBefore, TrainingSession::count(), 'Cancelling must not remove a row.');

        // The student is back in the line, and no training was recorded.
        $this->assertSame(TrainingQueueEntry::WAITING, $session->queueEntry->fresh()->status);
        $this->assertSame(0, TrainingEvaluation::count());

        // The teacher can work again.
        $this->assertNull($this->sessions()->activeForInstructor($this->teacher->id));
        $this->assertNull($this->board($this->teacherUser)['current']);

        $next = $this->sessions()->start($this->entryFor($this->students['Amina']), $this->teacher, $this->teacherUser, [
            'assigned_duration_minutes' => 30,
        ]);

        $this->assertSame(TrainingSession::IN_PROGRESS, $next->status);
    }

    /* 12 — the queue moves with the session. */
    public function test_a_student_being_trained_is_not_also_listed_as_waiting(): void
    {
        $this->queueAll();

        $this->sessions()->start($this->entryFor($this->students['Ali']), $this->teacher, $this->teacherUser, [
            'assigned_duration_minutes' => 30,
        ]);

        $waiting = collect($this->board($this->teacherUser)['queue'])->pluck('student')->all();

        $this->assertNotContains('Ali', $waiting);
        $this->assertContains('Amina', $waiting);
    }

    /* 14 — the profile guard is untouched. */
    public function test_an_instructor_without_a_profile_still_cannot_reach_the_console(): void
    {
        $orphan = $this->makeUser(Role::INSTRUCTOR, ['email' => 'orphan@example.test']);

        $this->actingAs($orphan)->get(route('instructor.training.index'))->assertForbidden();
    }
}
