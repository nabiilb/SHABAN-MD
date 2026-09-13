<?php

namespace Tests\Feature;

use App\Models\Instructor;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Student;
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
 * The admin board and the teacher console must describe the same queue.
 *
 * The reported bug: the admin board said two students were waiting while every
 * teacher console showed an empty line. The cause was that a teacher's queue was
 * built only from the students that teacher owns for the date, so a queued
 * student with no permanent instructor — exactly what the admin's own "Add to
 * Queue" form produces, since it lists every active student — was counted by
 * the admin and offered to nobody.
 *
 * Both views now ask TrainingQueueEntry::claimableBy(), and so does the claim
 * itself, which makes the invariant below true by construction: every waiting
 * student the admin counts is on at least one teacher's console.
 */
class TeacherQueueVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $xasanUser;

    private User $nasteexoUser;

    private Instructor $xasan;

    private Instructor $nasteexo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-13 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->xasanUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Xasan']);
        $this->nasteexoUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Nasteexo']);
        $this->xasan = $this->makeInstructor('Xasan', $this->xasanUser);
        $this->nasteexo = $this->makeInstructor('Nasteexo', $this->nasteexoUser);
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

    private function boards(): TrainingBoardService
    {
        return app(TrainingBoardService::class);
    }

    /** Two students the admin queued who belong to no teacher — the bug's shape. */
    private function queueUnassigned(string ...$names): array
    {
        $students = [];

        foreach ($names as $offset => $name) {
            Carbon::setTestNow(Carbon::parse('2026-09-13 09:00:00')->addMinutes($offset));

            $students[$name] = $this->makeStudent($name, null);
            $this->queue()->add($students[$name], $this->admin);
        }

        Carbon::setTestNow(Carbon::parse('2026-09-13 09:30:00'));

        return $students;
    }

    private function names($collection): array
    {
        return collect($collection)->map(fn ($entry) => $entry->student->full_name)->values()->all();
    }

    private function teacherQueueNames(User $teacher): array
    {
        return collect($this->boards()->board($teacher)['queue'])->pluck('student')->all();
    }

    /* A ------------------------------------------------------------- */
    public function test_two_waiting_students_are_counted_by_the_admin_and_shown_to_the_teacher(): void
    {
        $this->queueUnassigned('Ahmed', 'Mohamed');

        $adminBoard = $this->boards()->board(null);

        $this->assertSame(2, $adminBoard['stats']['waiting']);
        $this->assertSame(['Ahmed', 'Mohamed'], collect($adminBoard['queue'])->pluck('student')->all());

        // The console the bug left empty.
        $this->assertSame(['Ahmed', 'Mohamed'], $this->teacherQueueNames($this->xasanUser));
        $this->assertSame(['Ahmed', 'Mohamed'], $this->teacherQueueNames($this->nasteexoUser));
    }

    /**
     * The invariant behind A: whatever the admin counts, some teacher can act
     * on. This is the check that would have caught the bug on any data.
     */
    public function test_no_waiting_student_is_invisible_to_every_teacher(): void
    {
        $this->queueUnassigned('Ahmed', 'Mohamed');
        $this->queue()->add($this->makeStudent('Ilyas', $this->xasan), $this->admin);
        $this->queue()->add($this->makeStudent('Maryan', $this->nasteexo), $this->admin);

        $counted = TrainingQueueEntry::forDate(today())->waiting()->pluck('id')->all();

        $visible = Instructor::active()->pluck('id')
            ->flatMap(fn (int $id) => $this->queue()->waitingFor($id)->pluck('id'))
            ->unique()
            ->values()
            ->all();

        $this->assertSame(4, count($counted));
        $this->assertEqualsCanonicalizing($counted, $visible);
    }

    /* B ------------------------------------------------------------- */
    public function test_the_teacher_queue_is_ordered_first_come_first_served(): void
    {
        $this->queueUnassigned('Ahmed', 'Mohamed', 'Hassan');

        $this->assertSame(['Ahmed', 'Mohamed', 'Hassan'], $this->teacherQueueNames($this->xasanUser));
        $this->assertSame([1, 2, 3], collect($this->boards()->board($this->xasanUser)['queue'])->pluck('display_position')->all());
    }

    /* C + D --------------------------------------------------------- */
    public function test_a_completed_student_leaves_the_queue_and_keeps_no_position(): void
    {
        // Auto-start off, so the student behind stays waiting and can be seen
        // holding — and only holding — position #1.
        Setting::put('training_auto_start_next', '0');

        $students = $this->queueUnassigned('Ahmed', 'Mohamed');

        $this->completeTraining($this->xasan, $this->xasanUser);

        $entry = TrainingQueueEntry::where('student_id', $students['Ahmed']->id)->firstOrFail();
        $this->assertSame(TrainingQueueEntry::COMPLETED, $entry->status);

        // C: gone from the waiting line, on both boards.
        $this->assertNotContains('Ahmed', $this->teacherQueueNames($this->nasteexoUser));
        $this->assertNotContains('Ahmed', collect($this->boards()->board(null)['queue'])->pluck('student')->all());

        // D: and the admin's queue page gives them no number, while the student
        // still waiting is renumbered to #1.
        $page = $this->actingAs($this->admin)->get(route('admin.training.queue'));
        $positions = $page->viewData('livePositions');

        $this->assertFalse($positions->has($entry->id));
        $this->assertSame(1, $positions[TrainingQueueEntry::where('student_id', $students['Mohamed']->id)->value('id')]);
        $this->assertSame(1, $positions->count());
    }

    /* E + F + G ----------------------------------------------------- */
    public function test_starting_a_student_removes_them_from_the_line_and_promotes_the_next(): void
    {
        $this->queueUnassigned('Ahmed', 'Mohamed');

        $this->assertSame(2, $this->boards()->board(null)['stats']['waiting']);

        $session = $this->sessions()->startNext($this->xasan, $this->xasanUser, ['assigned_duration_minutes' => 30]);

        // E: the count drops.
        $this->assertSame(1, $this->boards()->board(null)['stats']['waiting']);

        // F: and they are in training rather than waiting.
        $this->assertSame('Ahmed', $session->student->full_name);
        $this->assertNotContains('Ahmed', $this->teacherQueueNames($this->nasteexoUser));
        $this->assertSame(TrainingSession::IN_PROGRESS, $session->status);

        // G: the one left is #1.
        $remaining = $this->boards()->board($this->nasteexoUser)['queue'];
        $this->assertSame('Mohamed', $remaining[0]['student']);
        $this->assertSame(1, $remaining[0]['display_position']);
    }

    /* H ------------------------------------------------------------- */
    public function test_two_teachers_cannot_start_the_same_student(): void
    {
        $students = $this->queueUnassigned('Ahmed', 'Mohamed');

        $entry = TrainingQueueEntry::where('student_id', $students['Ahmed']->id)->firstOrFail();

        $this->sessions()->start($entry, $this->xasan, $this->xasanUser, ['assigned_duration_minutes' => 30]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This student is already in training with another teacher.');

        $this->sessions()->start($entry->fresh(), $this->nasteexo, $this->nasteexoUser, ['assigned_duration_minutes' => 30]);
    }

    /* I ------------------------------------------------------------- */
    public function test_the_countdown_is_the_servers_and_survives_a_refresh(): void
    {
        $this->queueUnassigned('Ahmed', 'Mohamed');

        $session = $this->sessions()->startNext($this->xasan, $this->xasanUser, ['assigned_duration_minutes' => 30]);

        Carbon::setTestNow(now()->addMinutes(10));

        // Two independent reads of the board, as two page loads would be.
        $this->assertSame(1200, $this->boards()->board($this->xasanUser)['current']['remaining_seconds']);
        $this->assertSame(1200, $this->boards()->board($this->xasanUser)['current']['remaining_seconds']);
        $this->assertSame(1200, $session->fresh()->remaining_seconds);
    }

    /* J ------------------------------------------------------------- */
    public function test_ending_training_moves_the_student_to_attendance_pending_not_completed(): void
    {
        $this->queueUnassigned('Ahmed', 'Mohamed');

        $session = $this->sessions()->startNext($this->xasan, $this->xasanUser, ['assigned_duration_minutes' => 30]);

        // Let the clock run out rather than ending by hand.
        Carbon::setTestNow(now()->addMinutes(31));
        $this->sessions()->closeExpired($this->xasanUser);

        $this->assertSame(TrainingSession::ATTENDANCE_PENDING, $session->fresh()->status);
        $this->assertSame(TrainingQueueEntry::ATTENDANCE_PENDING, $session->fresh()->queueEntry->status);
        $this->assertTrue($this->boards()->board($this->xasanUser)['needs_evaluation']);
    }

    /* K + L --------------------------------------------------------- */
    public function test_evaluation_completes_the_student_and_frees_the_next_one(): void
    {
        $students = $this->queueUnassigned('Ahmed', 'Mohamed');

        $next = $this->completeTraining($this->xasan, $this->xasanUser);

        // K
        $ahmed = TrainingQueueEntry::where('student_id', $students['Ahmed']->id)->firstOrFail();
        $this->assertSame(TrainingQueueEntry::COMPLETED, $ahmed->status);
        $this->assertSame(TrainingSession::COMPLETED, TrainingSession::where('student_id', $students['Ahmed']->id)->value('status'));

        // L: auto-start is on by default, so Mohamed is already training —
        // either way he is the one the board offers next.
        $this->assertSame('Mohamed', $next?->student?->full_name);
        $this->assertSame('Mohamed', $this->boards()->board($this->xasanUser)['current']['student']);
    }

    /**
     * A student who does belong to a teacher stays that teacher's alone — the
     * fix opens up the unowned, it does not pool everybody.
     */
    public function test_an_owned_student_is_still_only_in_their_own_teachers_queue(): void
    {
        $this->queue()->add($this->makeStudent('Ilyas', $this->xasan), $this->admin);
        $this->queue()->add($this->makeStudent('Maryan', $this->nasteexo), $this->admin);

        $this->assertSame(['Ilyas'], $this->teacherQueueNames($this->xasanUser));
        $this->assertSame(['Maryan'], $this->teacherQueueNames($this->nasteexoUser));
    }

    /** A student asked for by name belongs to that teacher, nobody else. */
    public function test_a_preferred_teacher_takes_an_otherwise_unowned_student(): void
    {
        $student = $this->makeStudent('Ahmed', null);
        $this->queue()->add($student, $this->admin, ['preferred_instructor_id' => $this->nasteexo->id]);

        $this->assertSame(['Ahmed'], $this->teacherQueueNames($this->nasteexoUser));
        $this->assertSame([], $this->teacherQueueNames($this->xasanUser));
    }

    /**
     * The escape hatch for a centre that runs one line rather than a line per
     * teacher: every teacher sees the whole queue, including students who
     * permanently belong to someone else.
     */
    public function test_the_shared_queue_setting_gives_every_teacher_the_whole_line(): void
    {
        $this->queue()->add($this->makeStudent('Ilyas', $this->xasan), $this->admin);
        $this->queue()->add($this->makeStudent('Maryan', $this->nasteexo), $this->admin);
        $this->queueUnassigned('Ahmed');

        // Per-teacher by default.
        $this->assertSame(['Ilyas', 'Ahmed'], $this->teacherQueueNames($this->xasanUser));

        Setting::put('training_shared_queue', '1');

        foreach ([$this->xasanUser, $this->nasteexoUser] as $teacher) {
            $this->assertSame(['Ilyas', 'Maryan', 'Ahmed'], $this->teacherQueueNames($teacher));
        }

        // And a teacher can actually take someone else's student.
        $maryan = TrainingQueueEntry::whereHas('student', fn ($q) => $q->where('full_name', 'Maryan'))->firstOrFail();
        $session = $this->sessions()->start($maryan, $this->xasan, $this->xasanUser, ['assigned_duration_minutes' => 30]);

        $this->assertSame('Maryan', $session->student->full_name);
        $this->assertSame($this->xasan->id, $session->instructor_id);
    }

    /** A named preferred teacher still narrows an entry in shared mode. */
    public function test_a_preferred_teacher_is_respected_even_in_shared_mode(): void
    {
        Setting::put('training_shared_queue', '1');

        $this->queue()->add($this->makeStudent('Ahmed', null), $this->admin, [
            'preferred_instructor_id' => $this->nasteexo->id,
        ]);

        $this->assertSame(['Ahmed'], $this->teacherQueueNames($this->nasteexoUser));
        $this->assertSame([], $this->teacherQueueNames($this->xasanUser));
    }

    /** Runs one student all the way through, returning whatever started next. */
    private function completeTraining(Instructor $teacher, User $actor): ?TrainingSession
    {
        $session = $this->sessions()->startNext($teacher, $actor, ['assigned_duration_minutes' => 30]);

        $this->sessions()->end($session, $actor);
        $this->sessions()->evaluate($session->fresh(), [
            'attendance_status' => 'present',
            'evaluation' => 'good',
            'comment' => 'Steady.',
        ], $actor);

        return TrainingSession::where('instructor_id', $teacher->id)
            ->live()
            ->with('student')
            ->first();
    }
}
