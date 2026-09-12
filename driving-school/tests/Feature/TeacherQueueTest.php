<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\LessonTopic;
use App\Models\Role;
use App\Models\Student;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AttendanceTransferService;
use App\Services\TrainingBoardService;
use App\Services\TrainingQueueService;
use App\Services\TrainingSessionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Each teacher runs an independent FIFO queue made of their permanent students
 * plus anyone transferred to them for that date. Only the student who is
 * actually training has a clock.
 */
class TeacherQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $xasanUser;

    private User $nasteexoUser;

    private Instructor $xasan;

    private Instructor $nasteexo;

    /** @var array<string, Student> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-09 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->xasanUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Xasan']);
        $this->nasteexoUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Nasteexo']);
        $this->xasan = $this->makeInstructor('Xasan', $this->xasanUser);
        $this->nasteexo = $this->makeInstructor('Nasteexo', $this->nasteexoUser);

        // Xasan's own students, joining the line in this order.
        foreach (['Mohamed', 'Ali', 'Hassan', 'Abdi'] as $offset => $name) {
            $this->students[$name] = $this->makeStudent($name, $this->xasan);
            $this->queue()->add($this->students[$name], $this->admin);
            Carbon::setTestNow(Carbon::parse('2026-09-09 09:00:00')->addMinutes($offset + 1));
        }

        // Ahmed belongs permanently to Nasteexo.
        $this->students['Ahmed'] = $this->makeStudent('Ahmed', $this->nasteexo);
        $this->queue()->add($this->students['Ahmed'], $this->admin);

        Carbon::setTestNow(Carbon::parse('2026-09-09 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function queue(): TrainingQueueService
    {
        return app(TrainingQueueService::class);
    }

    private function sessions(): TrainingSessionService
    {
        return app(TrainingSessionService::class);
    }

    private function names($collection): array
    {
        return collect($collection)->map(fn ($e) => $e->student->full_name)->values()->all();
    }

    private function transferForToday(Student $student, Instructor $to): void
    {
        app(AttendanceTransferService::class)->transfer($student, $to, today(), $this->admin, 'Covering');
    }

    private function startFor(Instructor $teacher, User $actor, int $minutes = 30): TrainingSession
    {
        return $this->sessions()->startNext($teacher, $actor, ['assigned_duration_minutes' => $minutes]);
    }

    /* 1 ------------------------------------------------------------- */
    public function test_a_teacher_sees_only_their_permanent_students(): void
    {
        $this->assertSame(['Mohamed', 'Ali', 'Hassan', 'Abdi'], $this->names($this->queue()->waitingFor($this->xasan->id)));
        $this->assertSame(['Ahmed'], $this->names($this->queue()->waitingFor($this->nasteexo->id)));
    }

    /* 2 ------------------------------------------------------------- */
    public function test_a_transferred_student_joins_the_receiving_teachers_queue_for_that_date(): void
    {
        $this->transferForToday($this->students['Ahmed'], $this->xasan);

        $this->assertContains('Ahmed', $this->names($this->queue()->waitingFor($this->xasan->id)));
        $this->assertNotContains('Ahmed', $this->names($this->queue()->waitingFor($this->nasteexo->id)));
    }

    /* 3 + 20 -------------------------------------------------------- */
    public function test_a_transferred_student_returns_the_next_day_and_the_new_day_stands_alone(): void
    {
        $this->transferForToday($this->students['Ahmed'], $this->xasan);
        $this->assertContains('Ahmed', $this->names($this->queue()->waitingFor($this->xasan->id, '2026-09-09')));

        // 10/09 with no transfer: Ahmed is Nasteexo's again.
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
        $this->queue()->ensureQueuedFor($this->nasteexo->id, today(), $this->admin);
        $this->queue()->ensureQueuedFor($this->xasan->id, today(), $this->admin);

        $this->assertContains('Ahmed', $this->names($this->queue()->waitingFor($this->nasteexo->id, '2026-09-10')));
        $this->assertNotContains('Ahmed', $this->names($this->queue()->waitingFor($this->xasan->id, '2026-09-10')));

        // Yesterday's queue is untouched by today's.
        $this->assertContains('Ahmed', $this->names($this->queue()->waitingFor($this->xasan->id, '2026-09-09')));
    }

    /* 19 ------------------------------------------------------------ */
    public function test_a_transfer_never_changes_the_permanent_instructor(): void
    {
        $this->transferForToday($this->students['Ahmed'], $this->xasan);

        $this->assertSame($this->nasteexo->id, $this->students['Ahmed']->fresh()->current_instructor_id);
        $this->assertSame($this->xasan->id, $this->students['Ahmed']->fresh()->instructorIdOn('2026-09-09'));
        $this->assertSame($this->nasteexo->id, $this->students['Ahmed']->fresh()->instructorIdOn('2026-09-10'));
    }

    /* 4 + 17 -------------------------------------------------------- */
    public function test_a_teacher_can_only_run_one_session_at_a_time(): void
    {
        $this->startFor($this->xasan, $this->xasanUser);

        $second = $this->queue()->nextWaitingFor($this->xasan->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You already have a training session in progress.');

        $this->sessions()->start($second, $this->xasan, $this->xasanUser, ['assigned_duration_minutes' => 30]);
    }

    public function test_the_database_itself_refuses_a_second_live_session_for_a_teacher(): void
    {
        $this->startFor($this->xasan, $this->xasanUser);

        // Bypassing the service entirely still cannot produce a second one.
        $this->expectException(QueryException::class);

        TrainingSession::create([
            'student_id' => $this->students['Ali']->id,
            'instructor_id' => $this->xasan->id,
            'status' => TrainingSession::IN_PROGRESS,
            'assigned_duration_minutes' => 30,
            'started_at' => now(),
            'expected_end_at' => now()->addMinutes(30),
        ]);
    }

    /* 5 + 16 (the central rule) ------------------------------------- */
    public function test_waiting_students_have_no_session_and_no_running_clock(): void
    {
        $this->startFor($this->xasan, $this->xasanUser);

        $waiting = $this->queue()->waitingFor($this->xasan->id);
        $this->assertSame(['Ali', 'Hassan', 'Abdi'], $this->names($waiting));

        foreach ($waiting as $entry) {
            $this->assertSame(TrainingQueueEntry::WAITING, $entry->status);
            $this->assertSame(
                0,
                TrainingSession::where('student_id', $entry->student_id)->count(),
                "{$entry->student->full_name} is only waiting and must have no session, and so no started_at.",
            );
        }

        // Exactly one clock is running for this teacher.
        $this->assertSame(1, TrainingSession::where('instructor_id', $this->xasan->id)->running()->count());
    }

    /* 6 ------------------------------------------------------------- */
    public function test_the_clock_starts_only_when_training_starts(): void
    {
        $entry = $this->queue()->nextWaitingFor($this->xasan->id);
        $this->assertSame(0, TrainingSession::where('student_id', $entry->student_id)->count());

        Carbon::setTestNow(Carbon::parse('2026-09-09 10:00:00'));
        $session = $this->sessions()->start($entry, $this->xasan, $this->xasanUser, ['assigned_duration_minutes' => 30]);

        $this->assertSame('2026-09-09 10:00:00', $session->started_at->toDateTimeString());
        $this->assertSame(30 * 60, $session->remaining_seconds);
    }

    /* 7 + 8 --------------------------------------------------------- */
    public function test_a_thirty_minute_session_counts_down_thirty_minutes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 10:00:00'));
        $session = $this->startFor($this->xasan, $this->xasanUser, 30);

        $this->assertSame('2026-09-09 10:30:00', $session->expected_end_at->toDateTimeString());
        $this->assertSame(30 * 60, $session->remaining_seconds);
    }

    public function test_a_forty_minute_session_counts_down_forty_minutes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 10:00:00'));
        $session = $this->startFor($this->nasteexo, $this->nasteexoUser, 40);

        $this->assertSame('2026-09-09 10:40:00', $session->expected_end_at->toDateTimeString());
        $this->assertSame(40 * 60, $session->remaining_seconds);
    }

    /* 9 ------------------------------------------------------------- */
    public function test_ending_training_stops_the_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 10:00:00'));
        $session = $this->startFor($this->xasan, $this->xasanUser, 30);

        Carbon::setTestNow(Carbon::parse('2026-09-09 10:10:00'));
        $ended = $this->sessions()->end($session, $this->xasanUser);

        $this->assertSame(TrainingSession::ATTENDANCE_PENDING, $ended->status);
        $this->assertNotNull($ended->ended_at);

        // The clock is stopped, not merely hidden.
        Carbon::setTestNow(Carbon::parse('2026-09-09 10:25:00'));
        $this->assertSame(0, $ended->fresh()->remaining_seconds);
    }

    /* 10 + 6 (auto-advance) ----------------------------------------- */
    public function test_the_evaluation_advances_the_queue_automatically(): void
    {
        $first = $this->startFor($this->xasan, $this->xasanUser, 30);
        $this->sessions()->end($first, $this->xasanUser);

        $this->sessions()->evaluate($first->fresh(), [
            'attendance_status' => 'present', 'evaluation' => 'excellent',
        ], $this->xasanUser);

        $this->assertSame(TrainingSession::COMPLETED, $first->fresh()->status);

        // Ali was next and is already training, with his own fresh clock.
        $next = TrainingSession::where('student_id', $this->students['Ali']->id)->first();
        $this->assertNotNull($next, 'The next student should start automatically.');
        $this->assertSame(TrainingSession::IN_PROGRESS, $next->status);
        $this->assertSame(30 * 60, $next->remaining_seconds);

        $this->assertSame(['Hassan', 'Abdi'], $this->names($this->queue()->waitingFor($this->xasan->id)));
    }

    /* 11 + 12 ------------------------------------------------------- */
    public function test_the_queue_is_fifo_and_completed_students_lose_their_position(): void
    {
        $board = app(TrainingBoardService::class);

        $before = $board->instructorBoard($this->xasan->id);
        $this->assertSame([1, 2, 3, 4], array_column($before['queue'], 'display_position'));
        $this->assertSame(['Mohamed', 'Ali', 'Hassan', 'Abdi'], array_column($before['queue'], 'student'));

        $session = $this->startFor($this->xasan, $this->xasanUser, 30);
        $this->sessions()->end($session, $this->xasanUser);
        $this->sessions()->evaluate($session->fresh(), ['attendance_status' => 'present', 'evaluation' => 'good'], $this->xasanUser);

        // Mohamed is completed and Ali is training, so the line renumbers from
        // the first student still waiting.
        $after = $board->instructorBoard($this->xasan->id);
        $this->assertSame(['Hassan', 'Abdi'], array_column($after['queue'], 'student'));
        $this->assertSame([1, 2], array_column($after['queue'], 'display_position'));
        $this->assertNotContains('Mohamed', array_column($after['queue'], 'student'));
    }

    /* 13 ------------------------------------------------------------ */
    public function test_a_transferred_student_trains_exactly_like_a_permanent_one(): void
    {
        $this->transferForToday($this->students['Ahmed'], $this->xasan);

        // Put Ahmed at the head of Xasan's line.
        $ahmedEntry = TrainingQueueEntry::whereHas('student', fn ($q) => $q->where('full_name', 'Ahmed'))->first();
        $this->queue()->moveTo($ahmedEntry, 1, $this->admin);

        Carbon::setTestNow(Carbon::parse('2026-09-09 10:00:00'));
        $session = $this->startFor($this->xasan, $this->xasanUser, 40);

        $this->assertSame($this->students['Ahmed']->id, $session->student_id);
        $this->assertSame($this->xasan->id, $session->instructor_id);
        $this->assertSame(40 * 60, $session->remaining_seconds);

        $this->sessions()->end($session, $this->xasanUser);
        $evaluation = $this->sessions()->evaluate($session->fresh(), [
            'attendance_status' => 'present', 'evaluation' => 'very_good', 'comment' => 'Handled the transfer well.',
        ], $this->xasanUser);

        $this->assertSame('very_good', $evaluation->evaluation);
        $this->assertSame(TrainingSession::COMPLETED, $session->fresh()->status);

        // And the next student in Xasan's queue started by itself.
        $this->assertNotNull(TrainingSession::where('student_id', $this->students['Mohamed']->id)->first());

        // Ahmed's permanent instructor never moved.
        $this->assertSame($this->nasteexo->id, $this->students['Ahmed']->fresh()->current_instructor_id);
    }

    /* 14 ------------------------------------------------------------ */
    public function test_two_teachers_can_train_different_students_at_once(): void
    {
        $xasanSession = $this->startFor($this->xasan, $this->xasanUser, 30);
        $nasteexoSession = $this->startFor($this->nasteexo, $this->nasteexoUser, 40);

        $this->assertSame(TrainingSession::IN_PROGRESS, $xasanSession->status);
        $this->assertSame(TrainingSession::IN_PROGRESS, $nasteexoSession->status);
        $this->assertNotSame($xasanSession->student_id, $nasteexoSession->student_id);
        $this->assertSame(2, TrainingSession::running()->count());
    }

    /* 15 ------------------------------------------------------------ */
    public function test_a_teacher_cannot_see_or_control_another_teachers_queue(): void
    {
        $nasteexoSession = $this->startFor($this->nasteexo, $this->nasteexoUser, 30);

        // Xasan's board shows his own students only.
        $board = $this->actingAs($this->xasanUser)->getJson(route('instructor.training.board'))->assertOk()->json();

        $this->assertNull($board['current'], "Xasan must not see Nasteexo's session.");
        $this->assertSame(['Mohamed', 'Ali', 'Hassan', 'Abdi'], array_column($board['queue'], 'student'));
        $this->assertNotContains('Ahmed', array_column($board['queue'], 'student'));

        // And he cannot touch her session.
        $this->actingAs($this->xasanUser)
            ->post(route('instructor.training.end', $nasteexoSession))
            ->assertForbidden();

        // Nor start a student who is not his for the day.
        $ahmedEntry = TrainingQueueEntry::whereHas('student', fn ($q) => $q->where('full_name', 'Ahmed'))->first();

        $this->actingAs($this->xasanUser)
            ->post(route('instructor.training.start'), [
                'training_queue_entry_id' => $ahmedEntry->id,
                'assigned_duration_minutes' => 30,
            ])
            ->assertSessionHasErrors('training');
    }

    /* 16 ------------------------------------------------------------ */
    public function test_the_admin_sees_every_teacher_with_their_session_and_queue(): void
    {
        $this->startFor($this->xasan, $this->xasanUser, 30);

        $board = $this->actingAs($this->admin)->getJson(route('admin.training.board'))->assertOk()->json();

        $this->assertCount(2, $board['teachers']);

        $byName = collect($board['teachers'])->keyBy('instructor');

        $this->assertSame('Mohamed', $byName['Xasan']['current']['student']);
        $this->assertSame(['Ali', 'Hassan', 'Abdi'], array_column($byName['Xasan']['queue'], 'student'));

        $this->assertNull($byName['Nasteexo']['current']);
        $this->assertSame(['Ahmed'], array_column($byName['Nasteexo']['queue'], 'student'));
    }

    public function test_the_board_marks_students_permanent_or_transferred(): void
    {
        $this->transferForToday($this->students['Ahmed'], $this->xasan);

        $board = app(TrainingBoardService::class)->instructorBoard($this->xasan->id);
        $rows = collect($board['queue'])->keyBy('student');

        $this->assertSame('permanent', $rows['Mohamed']['ownership']);
        $this->assertSame('transferred', $rows['Ahmed']['ownership']);
        $this->assertSame('Nasteexo', $rows['Ahmed']['permanent_instructor']);
    }

    /* 18 ------------------------------------------------------------ */
    public function test_lesson_and_performance_land_on_the_right_training_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 10:00:00'));

        $session = $this->sessions()->start(
            $this->queue()->nextWaitingFor($this->xasan->id),
            $this->xasan,
            $this->xasanUser,
            ['assigned_duration_minutes' => 30, 'lesson_topic_id' => LessonTopic::where('code', 'parking')->value('id')],
        );

        $this->sessions()->end($session, $this->xasanUser);
        $evaluation = $this->sessions()->evaluate($session->fresh(), [
            'attendance_status' => 'present', 'evaluation' => 'excellent', 'comment' => 'Sharp parking.',
        ], $this->xasanUser);

        $attendance = Attendance::find($evaluation->attendance_id);

        $this->assertSame('2026-09-09', $attendance->attendance_date->toDateString());
        $this->assertSame($this->xasan->id, $attendance->instructor_id);
        $this->assertSame('Parking', $attendance->lesson->lessonTopic->name);
        $this->assertSame('excellent', $attendance->lesson->performance);
        $this->assertSame($session->id, $evaluation->training_session_id);
    }
}
