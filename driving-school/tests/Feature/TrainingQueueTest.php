<?php

namespace Tests\Feature;

use App\Models\Instructor;
use App\Models\LessonTopic;
use App\Models\Role;
use App\Models\Student;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingQueueService;
use App\Services\TrainingSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TrainingQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacherUser;

    private Instructor $teacher;

    /** @var array<string, Student> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->teacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Macallin X']);
        $this->teacher = $this->makeInstructor('Macallin X', $this->teacherUser);

        foreach (['Mohamed', 'Ali', 'Hassan', 'Abdi', 'Yusuf'] as $name) {
            $this->students[$name] = $this->makeStudent($name, $this->teacher);
        }
    }

    private function queue(): TrainingQueueService
    {
        return app(TrainingQueueService::class);
    }

    private function fillQueue(): void
    {
        foreach ($this->students as $student) {
            $this->queue()->add($student, $this->admin);
        }
    }

    /* ----------------------------------------------------------------
     | Ordering
     | ---------------------------------------------------------------- */

    public function test_students_take_positions_in_the_order_they_join(): void
    {
        $this->fillQueue();

        $this->assertSame(
            ['Mohamed', 'Ali', 'Hassan', 'Abdi', 'Yusuf'],
            $this->queue()->waitingList()->map(fn ($e) => $e->student->full_name)->all(),
        );

        $this->assertSame([1, 2, 3, 4, 5], $this->queue()->waitingList()->pluck('position')->all());
    }

    public function test_a_student_cannot_join_the_same_day_twice(): void
    {
        $this->queue()->add($this->students['Mohamed'], $this->admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Mohamed is already in today's queue.");

        $this->queue()->add($this->students['Mohamed'], $this->admin);
    }

    public function test_moving_a_student_slides_the_others_along(): void
    {
        $this->fillQueue();

        $yusuf = TrainingQueueEntry::whereHas('student', fn ($q) => $q->where('full_name', 'Yusuf'))->first();
        $this->queue()->moveTo($yusuf, 1, $this->admin);

        $this->assertSame(
            ['Yusuf', 'Mohamed', 'Ali', 'Hassan', 'Abdi'],
            $this->queue()->waitingList()->map(fn ($e) => $e->student->full_name)->all(),
        );
        $this->assertSame([1, 2, 3, 4, 5], $this->queue()->waitingList()->pluck('position')->all());
    }

    public function test_removing_a_student_closes_the_gap(): void
    {
        $this->fillQueue();

        $ali = TrainingQueueEntry::whereHas('student', fn ($q) => $q->where('full_name', 'Ali'))->first();
        $this->queue()->remove($ali, $this->admin);

        $this->assertSame(
            ['Mohamed', 'Hassan', 'Abdi', 'Yusuf'],
            $this->queue()->waitingList()->map(fn ($e) => $e->student->full_name)->all(),
        );
        $this->assertSame([1, 2, 3, 4], $this->queue()->waitingList()->pluck('position')->all());
        $this->assertSame(TrainingQueueEntry::CANCELLED, $ali->fresh()->status);
    }

    public function test_a_student_in_training_cannot_be_removed_from_the_queue(): void
    {
        $entry = $this->queue()->add($this->students['Mohamed'], $this->admin);
        app(TrainingSessionService::class)->start($entry, $this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('End the training session before removing this student from the queue.');

        $this->queue()->remove($entry->fresh(), $this->admin);
    }

    public function test_waiting_time_is_measured_from_when_they_joined(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00'));
        $entry = $this->queue()->add($this->students['Mohamed'], $this->admin);

        Carbon::setTestNow(Carbon::parse('2026-09-11 10:12:00'));
        $this->assertSame(12, $entry->fresh()->waiting_minutes);

        Carbon::setTestNow();
    }

    public function test_each_teachers_queue_holds_only_their_own_students(): void
    {
        $other = $this->makeInstructor('Macallin Y');

        // Mohamed belongs to the other teacher; Ali stays with ours.
        $this->students['Mohamed']->forceFill(['current_instructor_id' => $other->id])->save();

        $this->queue()->add($this->students['Mohamed'], $this->admin);
        $this->queue()->add($this->students['Ali'], $this->admin);

        $this->assertSame('Ali', $this->queue()->nextWaitingFor($this->teacher->id)?->student->full_name);
        $this->assertSame('Mohamed', $this->queue()->nextWaitingFor($other->id)?->student->full_name);

        // And neither queue contains the other's student.
        $this->assertSame(
            ['Ali'],
            $this->queue()->waitingFor($this->teacher->id)->map(fn ($e) => $e->student->full_name)->all(),
        );
    }

    /* ----------------------------------------------------------------
     | Through the HTTP layer
     | ---------------------------------------------------------------- */

    public function test_an_admin_can_add_move_and_remove_queue_entries(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.training.queue.store'), ['student_id' => $this->students['Mohamed']->id])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('admin.training.queue.store'), ['student_id' => $this->students['Ali']->id])
            ->assertRedirect();

        $ali = TrainingQueueEntry::whereHas('student', fn ($q) => $q->where('full_name', 'Ali'))->first();

        $this->actingAs($this->admin)
            ->post(route('admin.training.queue.move', $ali), ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame('Ali', $this->queue()->waitingList()->first()->student->full_name);

        $this->actingAs($this->admin)
            ->delete(route('admin.training.queue.destroy', $ali))
            ->assertRedirect();

        $this->assertSame(1, $this->queue()->waitingList()->count());
    }

    public function test_a_teacher_cannot_rearrange_the_queue(): void
    {
        $entry = $this->queue()->add($this->students['Mohamed'], $this->admin);

        $this->actingAs($this->teacherUser)
            ->post(route('admin.training.queue.store'), ['student_id' => $this->students['Ali']->id])
            ->assertForbidden();

        $this->actingAs($this->teacherUser)
            ->post(route('admin.training.queue.move', $entry), ['direction' => 'up'])
            ->assertForbidden();

        $this->actingAs($this->teacherUser)
            ->delete(route('admin.training.queue.destroy', $entry))
            ->assertForbidden();
    }

    public function test_a_teacher_can_start_end_and_evaluate_through_the_console(): void
    {
        $entry = $this->queue()->add($this->students['Mohamed'], $this->admin);

        $this->actingAs($this->teacherUser)
            ->post(route('instructor.training.start'), [
                'training_queue_entry_id' => $entry->id,
                'assigned_duration_minutes' => 30,
                'lesson_topic_id' => LessonTopic::where('code', 'parking')->value('id'),
            ])
            ->assertRedirect();

        $session = TrainingSession::firstOrFail();
        $this->assertSame(TrainingSession::IN_PROGRESS, $session->status);

        $this->actingAs($this->teacherUser)
            ->post(route('instructor.training.end', $session))
            ->assertRedirect();

        $this->assertSame(TrainingSession::ATTENDANCE_PENDING, $session->fresh()->status);

        $this->actingAs($this->teacherUser)
            ->post(route('instructor.training.evaluate', $session), [
                'attendance_status' => 'present',
                'evaluation' => 'very_good',
                'comment' => 'Good progress.',
            ])
            ->assertRedirect();

        $this->assertSame(TrainingSession::COMPLETED, $session->fresh()->status);
        $this->assertSame('very_good', $session->fresh()->evaluation->evaluation);
    }

    public function test_a_teacher_cannot_touch_another_teachers_session(): void
    {
        $entry = $this->queue()->add($this->students['Mohamed'], $this->admin);
        $session = app(TrainingSessionService::class)
            ->start($entry, $this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);

        $intruderUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Macallin Z']);
        $this->makeInstructor('Macallin Z', $intruderUser);

        $this->actingAs($intruderUser)->post(route('instructor.training.end', $session))->assertForbidden();
        $this->actingAs($intruderUser)->post(route('instructor.training.extend', $session), ['minutes' => 10])->assertForbidden();
        $this->actingAs($intruderUser)->post(route('instructor.training.pause', $session))->assertForbidden();
        $this->actingAs($intruderUser)
            ->post(route('instructor.training.evaluate', $session), ['attendance_status' => 'present', 'evaluation' => 'good'])
            ->assertForbidden();

        $this->assertSame(TrainingSession::IN_PROGRESS, $session->fresh()->status);
    }

    public function test_starting_a_student_another_teacher_already_took_shows_the_conflict_message(): void
    {
        $entry = $this->queue()->add($this->students['Mohamed'], $this->admin);
        app(TrainingSessionService::class)->start($entry, $this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);

        $rivalUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Macallin Y']);
        $this->makeInstructor('Macallin Y', $rivalUser);

        $this->actingAs($rivalUser)
            ->post(route('instructor.training.start'), [
                'training_queue_entry_id' => $entry->id,
                'assigned_duration_minutes' => 30,
            ])
            ->assertSessionHasErrors('training');

        $this->assertSame(1, TrainingSession::count());
    }

    public function test_the_evaluation_requires_a_rating_when_the_student_attended(): void
    {
        $entry = $this->queue()->add($this->students['Mohamed'], $this->admin);
        $session = app(TrainingSessionService::class)
            ->start($entry, $this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);
        app(TrainingSessionService::class)->end($session, $this->teacherUser);

        $this->actingAs($this->teacherUser)
            ->post(route('instructor.training.evaluate', $session), ['attendance_status' => 'present'])
            ->assertSessionHasErrors('evaluation');

        $this->assertSame(TrainingSession::ATTENDANCE_PENDING, $session->fresh()->status);
    }

    /* ----------------------------------------------------------------
     | The live board
     | ---------------------------------------------------------------- */

    public function test_the_board_endpoint_returns_the_current_session_and_queue(): void
    {
        $this->fillQueue();
        $entry = $this->queue()->waitingList()->first();
        app(TrainingSessionService::class)->start($entry, $this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);

        $this->actingAs($this->teacherUser)
            ->getJson(route('instructor.training.board'))
            ->assertOk()
            ->assertJsonPath('current.student', 'Mohamed')
            ->assertJsonPath('current.status', 'in_progress')
            ->assertJsonPath('current.total_minutes', 30)
            ->assertJsonPath('queue.0.student', 'Ali')
            ->assertJsonPath('queue_total', 4)
            ->assertJsonPath('next', 'Ali');
    }

    public function test_the_board_closes_a_session_whose_clock_ran_out(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00'));
        $entry = $this->queue()->add($this->students['Mohamed'], $this->admin);
        $session = app(TrainingSessionService::class)
            ->start($entry, $this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);

        Carbon::setTestNow(Carbon::parse('2026-09-11 10:31:00'));

        $this->actingAs($this->teacherUser)
            ->getJson(route('instructor.training.board'))
            ->assertOk()
            ->assertJsonPath('current.status', 'attendance_pending')
            ->assertJsonPath('needs_evaluation', true);

        $this->assertSame(TrainingSession::ATTENDANCE_PENDING, $session->fresh()->status);

        Carbon::setTestNow();
    }

    public static function trainingRoutes(): array
    {
        return [
            'admin board' => ['admin.training.index'],
            'admin queue' => ['admin.training.queue'],
            'admin history' => ['admin.training.history'],
            'teacher console' => ['instructor.training.index'],
        ];
    }

    #[DataProvider('trainingRoutes')]
    public function test_a_student_cannot_reach_any_training_screen(string $route): void
    {
        $studentUser = $this->makeUser(Role::STUDENT);
        $this->students['Mohamed']->forceFill(['user_id' => $studentUser->id])->save();

        $this->actingAs($studentUser)->get(route($route))->assertForbidden();
    }
}
