<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\LessonTopic;
use App\Models\Role;
use App\Models\Student;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingQueueService;
use App\Services\TrainingSessionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The training workflow:
 *   waiting → training_in_progress → attendance_pending → completed
 */
class TrainingSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherUser;

    private User $otherTeacherUser;

    private Instructor $teacher;

    private Instructor $otherTeacher;

    private Student $ahmed;

    private Student $mohamed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $this->teacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Macallin X']);
        $this->otherTeacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Macallin Y']);
        $this->teacher = $this->makeInstructor('Macallin X', $this->teacherUser);
        $this->otherTeacher = $this->makeInstructor('Macallin Y', $this->otherTeacherUser);

        $this->ahmed = $this->makeStudent('Ahmed', $this->teacher);
        $this->mohamed = $this->makeStudent('Mohamed', $this->teacher);
    }

    private function queue(Student $student): TrainingQueueEntry
    {
        return app(TrainingQueueService::class)->add($student, $this->teacherUser);
    }

    private function service(): TrainingSessionService
    {
        return app(TrainingSessionService::class);
    }

    private function startFor(Student $student, ?Instructor $teacher = null, int $minutes = 30): TrainingSession
    {
        return $this->service()->start(
            $this->queue($student),
            $teacher ?? $this->teacher,
            $this->teacherUser,
            ['assigned_duration_minutes' => $minutes, 'lesson_topic_id' => LessonTopic::where('code', 'parking')->value('id')],
        );
    }

    /* ----------------------------------------------------------------
     | Starting
     | ---------------------------------------------------------------- */

    public function test_starting_a_session_records_the_clock_in_the_database(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00'));

        $session = $this->startFor($this->ahmed, minutes: 30);

        $this->assertSame(TrainingSession::IN_PROGRESS, $session->status);
        $this->assertSame('2026-09-11 10:00:00', $session->started_at->toDateTimeString());
        $this->assertSame('2026-09-11 10:30:00', $session->expected_end_at->toDateTimeString());
        $this->assertSame(30, $session->assigned_duration_minutes);

        // The queue entry follows the session.
        $this->assertSame(TrainingQueueEntry::TRAINING_IN_PROGRESS, $session->queueEntry->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_the_countdown_comes_from_the_stored_timestamps(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00'));
        $session = $this->startFor($this->ahmed, minutes: 30);

        // Time passes; a page refresh re-reads the same stored end time.
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:05:45'));

        $this->assertSame(24 * 60 + 15, $session->fresh()->remaining_seconds);
        $this->assertSame('24:15', $session->fresh()->remaining_for_humans);

        Carbon::setTestNow();
    }

    public function test_a_second_teacher_cannot_start_the_same_student(): void
    {
        $entry = $this->queue($this->ahmed);

        $this->service()->start($entry, $this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This student is already in training with another teacher.');

        $this->service()->start($entry->fresh(), $this->otherTeacher, $this->otherTeacherUser, ['assigned_duration_minutes' => 30]);
    }

    public function test_only_one_live_session_can_exist_per_student(): void
    {
        $this->startFor($this->ahmed);

        // Even bypassing the queue, the database refuses a second live session.
        $this->expectException(QueryException::class);

        TrainingSession::create([
            'student_id' => $this->ahmed->id,
            'instructor_id' => $this->otherTeacher->id,
            'status' => TrainingSession::IN_PROGRESS,
            'assigned_duration_minutes' => 30,
            'started_at' => now(),
            'expected_end_at' => now()->addMinutes(30),
        ]);
    }

    public function test_a_completed_student_cannot_be_started_again(): void
    {
        $session = $this->startFor($this->ahmed);
        $this->service()->end($session, $this->teacherUser);
        $this->service()->evaluate($session->fresh(), ['attendance_status' => 'present', 'evaluation' => 'good'], $this->teacherUser);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This student has already completed training today.');

        $this->service()->start($session->queueEntry->fresh(), $this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);
    }

    /* ----------------------------------------------------------------
     | Ending — never straight to completed
     | ---------------------------------------------------------------- */

    public function test_ending_early_moves_the_student_to_attendance_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00'));
        $session = $this->startFor($this->ahmed, minutes: 30);

        Carbon::setTestNow(Carbon::parse('2026-09-11 10:12:00'));
        $ended = $this->service()->end($session, $this->teacherUser);

        $this->assertSame(TrainingSession::ATTENDANCE_PENDING, $ended->status);
        $this->assertTrue($ended->ended_early);
        $this->assertSame(12, $ended->actual_minutes);
        $this->assertSame(TrainingQueueEntry::ATTENDANCE_PENDING, $ended->queueEntry->fresh()->status);

        // Crucially, not completed — the evaluation has not happened yet.
        $this->assertNull($ended->evaluation);

        Carbon::setTestNow();
    }

    public function test_the_clock_running_out_ends_the_session_automatically(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00'));
        $session = $this->startFor($this->ahmed, minutes: 30);

        Carbon::setTestNow(Carbon::parse('2026-09-11 10:31:00'));
        $closed = $this->service()->closeExpired($this->teacherUser);

        $this->assertSame(1, $closed);
        $this->assertSame(TrainingSession::ATTENDANCE_PENDING, $session->fresh()->status);
        $this->assertFalse($session->fresh()->ended_early);

        Carbon::setTestNow();
    }

    public function test_ending_twice_is_harmless(): void
    {
        $session = $this->startFor($this->ahmed);

        $this->service()->end($session, $this->teacherUser);
        $endedAt = $session->fresh()->ended_at;

        $this->service()->end($session->fresh(), $this->teacherUser);

        $this->assertEquals($endedAt, $session->fresh()->ended_at);
        $this->assertSame(TrainingSession::ATTENDANCE_PENDING, $session->fresh()->status);
    }

    /* ----------------------------------------------------------------
     | Extend and pause
     | ---------------------------------------------------------------- */

    public function test_extending_pushes_the_expected_end_out(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00'));
        $session = $this->startFor($this->ahmed, minutes: 30);

        $this->service()->extend($session, 10, $this->teacherUser);
        $session->refresh();

        $this->assertSame('2026-09-11 10:40:00', $session->expected_end_at->toDateTimeString());
        $this->assertSame(10, $session->extended_minutes);
        $this->assertSame(40, $session->total_minutes);

        Carbon::setTestNow();
    }

    public function test_pausing_holds_the_clock_and_resuming_gives_the_time_back(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00'));
        $session = $this->startFor($this->ahmed, minutes: 30);

        Carbon::setTestNow(Carbon::parse('2026-09-11 10:10:00'));
        $this->service()->pause($session, $this->teacherUser);

        // Five minutes pass while paused; the remaining time does not move.
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:15:00'));
        $this->assertSame(20 * 60, $session->fresh()->remaining_seconds);

        $this->service()->resume($session->fresh(), $this->teacherUser);
        $session->refresh();

        $this->assertSame(TrainingSession::IN_PROGRESS, $session->status);
        $this->assertSame('2026-09-11 10:35:00', $session->expected_end_at->toDateTimeString());
        $this->assertSame(20 * 60, $session->remaining_seconds);

        Carbon::setTestNow();
    }

    /* ----------------------------------------------------------------
     | Evaluation is what completes the student
     | ---------------------------------------------------------------- */

    public function test_evaluation_completes_the_student_and_records_everything(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00'));
        $session = $this->startFor($this->ahmed, minutes: 30);

        Carbon::setTestNow(Carbon::parse('2026-09-11 10:30:00'));
        $this->service()->end($session, $this->teacherUser);

        $evaluation = $this->service()->evaluate($session->fresh(), [
            'attendance_status' => 'present',
            'evaluation' => 'excellent',
            'comment' => 'Student performed very well during the training.',
        ], $this->teacherUser);

        $session->refresh();

        $this->assertSame(TrainingSession::COMPLETED, $session->status);
        $this->assertSame(TrainingQueueEntry::COMPLETED, $session->queueEntry->fresh()->status);

        $this->assertSame('excellent', $evaluation->evaluation);
        $this->assertSame('Student performed very well during the training.', $evaluation->comment);
        $this->assertSame($this->teacher->id, $evaluation->instructor_id);
        $this->assertNotNull($evaluation->evaluated_at);

        // The training centre's daily register was written too.
        $attendance = Attendance::where('student_id', $this->ahmed->id)->first();
        $this->assertNotNull($attendance);
        $this->assertSame('present', $attendance->status);
        $this->assertSame($this->teacher->id, $attendance->instructor_id);
        $this->assertSame($evaluation->attendance_id, $attendance->id);

        // ...including the lesson and its rating.
        $this->assertNotNull($attendance->lesson_id);
        $this->assertSame('excellent', $attendance->lesson->performance);
        $this->assertSame('Parking', $attendance->lesson->lessonTopic->name);

        Carbon::setTestNow();
    }

    public function test_a_session_cannot_be_evaluated_before_it_ends(): void
    {
        $session = $this->startFor($this->ahmed);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('End the training session before evaluating it.');

        $this->service()->evaluate($session, ['attendance_status' => 'present', 'evaluation' => 'good'], $this->teacherUser);
    }

    public function test_a_session_cannot_be_evaluated_twice(): void
    {
        $session = $this->startFor($this->ahmed);
        $this->service()->end($session, $this->teacherUser);
        $this->service()->evaluate($session->fresh(), ['attendance_status' => 'present', 'evaluation' => 'good'], $this->teacherUser);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This session has already been evaluated.');

        $this->service()->evaluate($session->fresh(), ['attendance_status' => 'present', 'evaluation' => 'good'], $this->teacherUser);
    }

    public function test_an_absent_student_gets_no_lesson(): void
    {
        $session = $this->startFor($this->ahmed);
        $this->service()->end($session, $this->teacherUser);

        $this->service()->evaluate($session->fresh(), [
            'attendance_status' => 'absent',
            'evaluation' => null,
        ], $this->teacherUser);

        $this->assertSame('absent', Attendance::where('student_id', $this->ahmed->id)->first()->status);
        $this->assertSame(0, Lesson::count());
    }

    /* ----------------------------------------------------------------
     | The next student
     | ---------------------------------------------------------------- */

    public function test_completing_a_student_makes_the_next_one_eligible(): void
    {
        $ahmedEntry = $this->queue($this->ahmed);
        $this->queue($this->mohamed);

        $session = $this->service()->start($ahmedEntry, $this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);

        // While Ahmed trains, Mohamed is the next in line.
        $this->assertSame('Mohamed', app(TrainingQueueService::class)->nextWaiting()?->student->full_name);

        $this->service()->end($session, $this->teacherUser);
        $this->service()->evaluate($session->fresh(), ['attendance_status' => 'present', 'evaluation' => 'good'], $this->teacherUser);

        // Now he can actually be started.
        $next = $this->service()->startNext($this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 40]);

        $this->assertNotNull($next);
        $this->assertSame($this->mohamed->id, $next->student_id);
        $this->assertSame(40, $next->assigned_duration_minutes);
        $this->assertSame(TrainingSession::IN_PROGRESS, $next->status);
    }

    public function test_start_next_returns_nothing_when_the_queue_is_empty(): void
    {
        $this->assertNull($this->service()->startNext($this->teacher, $this->teacherUser));
    }
}
