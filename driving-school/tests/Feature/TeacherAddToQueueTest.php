<?php

namespace Tests\Feature;

use App\Events\TrainingBoardChanged;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * A teacher putting a student in the day's waiting line.
 *
 * Adding is not starting: it only ever writes `waiting`, and the countdown
 * still begins when somebody presses Select.
 */
class TeacherAddToQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacherUser;

    private Instructor $teacher;

    private Instructor $other;

    /** @var array<string, Student> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-13 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->teacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Xasan']);
        $this->teacher = $this->makeInstructor('Xasan', $this->teacherUser);
        $this->other = $this->makeInstructor('Nasteexo');

        // A teacher's own students are enrolled in their line automatically the
        // moment the board is read, so the Add action is for everyone else:
        // students with no teacher, and students who have already finished.
        foreach (['Ahmed', 'Mohamed', 'Hassan'] as $name) {
            $this->students[$name] = $this->makeStudent($name, null);
        }

        $this->students['Ilyas'] = $this->makeStudent('Ilyas', $this->teacher);
        $this->students['Faadumo'] = $this->makeStudent('Faadumo', $this->other);
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

    private function addAs(User $user, Student $student, ?int $minutes = 30)
    {
        return $this->actingAs($user)->post(route('instructor.training.queue.store'), array_filter([
            'student_id' => $student->id,
            'assigned_duration_minutes' => $minutes,
        ]));
    }

    private function teacherQueueNames(): array
    {
        return collect($this->boards()->board($this->teacherUser)['queue'])->pluck('student')->all();
    }

    /* A + B + C + D + F -------------------------------------------- */
    public function test_a_teacher_adds_a_student_who_joins_the_line_waiting(): void
    {
        $this->addAs($this->teacherUser, $this->students['Ahmed'])->assertRedirect();

        $entry = TrainingQueueEntry::where('student_id', $this->students['Ahmed']->id)->firstOrFail();

        // B: waiting, not training — and no session exists yet.
        $this->assertSame(TrainingQueueEntry::WAITING, $entry->status);
        $this->assertSame(0, TrainingSession::count());

        // Provenance and the clock the waiting time is measured from.
        $this->assertSame($this->teacherUser->id, $entry->created_by);
        $this->assertTrue($entry->joined_at->equalTo(now()));
        $this->assertSame(30, $entry->assigned_duration_minutes);

        $board = $this->boards()->board($this->teacherUser);

        // C + D — Ahmed is in the line; Ilyas is there too, enrolled from
        // ownership when the board was read.
        $names = collect($board['queue'])->pluck('student')->all();
        $this->assertContains('Ahmed', $names);
        $this->assertSame(range(1, count($names)), collect($board['queue'])->pluck('display_position')->all());

        // F
        $this->assertSame(count($names), $board['stats']['waiting']);
    }

    /* C again — the example from the brief: Ahmed, Mohamed, then Hassan. */
    public function test_each_student_added_takes_the_next_position(): void
    {
        foreach (['Ahmed', 'Mohamed', 'Hassan'] as $name) {
            $this->addAs($this->teacherUser, $this->students[$name]);
            Carbon::setTestNow(now()->addMinute());
        }

        $queue = collect($this->boards()->board($this->teacherUser)['queue']);

        // Ahmed, Mohamed and Hassan keep the order they were added in.
        $this->assertSame(
            ['Ahmed', 'Mohamed', 'Hassan'],
            $queue->pluck('student')->reject(fn ($name) => $name === 'Ilyas')->values()->all(),
        );
        $this->assertSame(range(1, $queue->count()), $queue->pluck('display_position')->all());
    }

    /* E ------------------------------------------------------------- */
    public function test_the_student_appears_on_the_admin_board_too(): void
    {
        $this->addAs($this->teacherUser, $this->students['Ahmed']);

        $adminBoard = $this->boards()->board(null);

        $this->assertContains('Ahmed', collect($adminBoard['queue'])->pluck('student')->all());
        // The admin counts the whole floor, so their total covers this
        // teacher's line and the other teacher's students too.
        $this->assertGreaterThanOrEqual(
            $this->boards()->board($this->teacherUser)['stats']['waiting'],
            $adminBoard['stats']['waiting'],
        );
        $this->assertContains('Ahmed', collect($this->boards()->board($this->teacherUser)['queue'])->pluck('student')->all());

        // And on the admin's own queue page.
        $page = $this->actingAs($this->admin)->get(route('admin.training.queue'));
        $page->assertOk()->assertSee('Ahmed');
    }

    /* G + H ---------------------------------------------------------- */
    public function test_a_waiting_student_cannot_be_added_twice(): void
    {
        $this->addAs($this->teacherUser, $this->students['Ahmed']);

        $this->addAs($this->teacherUser, $this->students['Ahmed'])
            ->assertSessionHasErrors(['student_id' => 'This student is already in the waiting queue.']);

        $this->assertSame(1, TrainingQueueEntry::where('student_id', $this->students['Ahmed']->id)->count());
    }

    /* I -------------------------------------------------------------- */
    public function test_a_student_in_training_cannot_be_added(): void
    {
        $this->addAs($this->teacherUser, $this->students['Ahmed']);
        $this->sessions()->startNext($this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);

        $this->addAs($this->teacherUser, $this->students['Ahmed'])
            ->assertSessionHasErrors(['student_id' => 'This student is currently in training.']);

        $this->assertSame(1, TrainingQueueEntry::where('student_id', $this->students['Ahmed']->id)->count());
    }

    /* J -------------------------------------------------------------- */
    public function test_a_student_awaiting_evaluation_cannot_be_added(): void
    {
        $this->addAs($this->teacherUser, $this->students['Ahmed']);
        $session = $this->sessions()->startNext($this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);
        $this->sessions()->end($session, $this->teacherUser);

        $this->addAs($this->teacherUser, $this->students['Ahmed'])
            ->assertSessionHasErrors(['student_id' => "This student's attendance/evaluation is pending."]);
    }

    /* K — the centre runs repeat training, so a finished student may rejoin. */
    public function test_a_completed_student_may_rejoin_the_same_day(): void
    {
        $this->addAs($this->teacherUser, $this->students['Ahmed']);
        $session = $this->sessions()->startNext($this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);
        $this->sessions()->end($session, $this->teacherUser);
        $this->sessions()->evaluate($session->fresh(), [
            'attendance_status' => 'present', 'evaluation' => 'good',
        ], $this->teacherUser);

        $this->addAs($this->teacherUser, $this->students['Ahmed'])->assertSessionHasNoErrors();

        $entry = TrainingQueueEntry::where('student_id', $this->students['Ahmed']->id)->firstOrFail();

        $this->assertSame(TrainingQueueEntry::WAITING, $entry->status);
        // The finished session is still the day's history.
        $this->assertSame(1, TrainingSession::where('status', TrainingSession::COMPLETED)->count());
    }

    /* L -------------------------------------------------------------- */
    public function test_two_simultaneous_adds_cannot_create_two_entries(): void
    {
        $student = $this->students['Ahmed'];

        // The unique key on (student_id, queue_date) is the backstop behind the
        // lock, so bypass the service entirely and prove the database refuses.
        TrainingQueueEntry::create([
            'student_id' => $student->id,
            'queue_date' => today()->toDateString(),
            'position' => 1,
            'status' => TrainingQueueEntry::WAITING,
            'joined_at' => now(),
        ]);

        try {
            TrainingQueueEntry::create([
                'student_id' => $student->id,
                'queue_date' => today()->toDateString(),
                'position' => 2,
                'status' => TrainingQueueEntry::WAITING,
                'joined_at' => now(),
            ]);
            $this->fail('The database allowed the same student into the queue twice.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage());
        }

        $this->assertSame(1, TrainingQueueEntry::where('student_id', $student->id)->count());
    }

    /* M -------------------------------------------------------------- */
    public function test_a_completed_student_stops_consuming_a_position(): void
    {
        Setting::put('training_auto_start_next', '0');

        $this->addAs($this->teacherUser, $this->students['Ahmed']);
        Carbon::setTestNow(now()->addMinute());
        $this->addAs($this->teacherUser, $this->students['Mohamed']);

        $session = $this->sessions()->startNext($this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);
        $this->sessions()->end($session, $this->teacherUser);
        $this->sessions()->evaluate($session->fresh(), [
            'attendance_status' => 'present', 'evaluation' => 'good',
        ], $this->teacherUser);

        $queue = collect($this->boards()->board($this->teacherUser)['queue']);

        $this->assertNotContains('Ahmed', $queue->pluck('student')->all());
        $this->assertSame('Mohamed', $queue->first()['student']);
        $this->assertSame(1, $queue->first()['display_position']);
        $this->assertSame(range(1, $queue->count()), $queue->pluck('display_position')->all());
    }

    /* N -------------------------------------------------------------- */
    public function test_only_a_teacher_or_admin_may_add_and_only_their_own_students(): void
    {
        $student = $this->makeUser(Role::STUDENT);

        $this->addAs($student, $this->students['Ahmed'])->assertForbidden();

        // A teacher cannot queue somebody else's student into a line they
        // would not then be able to see.
        $this->addAs($this->teacherUser, $this->students['Faadumo'])
            ->assertSessionHasErrors(['student_id' => 'This student belongs to another teacher.']);

        $this->assertSame(0, TrainingQueueEntry::where('student_id', $this->students['Faadumo']->id)->count());
    }

    /** A teacher is given no admin queue controls along with it. */
    public function test_a_teacher_still_cannot_reorder_or_remove_the_line(): void
    {
        $this->addAs($this->teacherUser, $this->students['Ahmed']);
        $entry = TrainingQueueEntry::firstOrFail();

        $this->actingAs($this->teacherUser)
            ->post(route('admin.training.queue.move', $entry), ['direction' => 'up'])
            ->assertForbidden();

        $this->actingAs($this->teacherUser)
            ->delete(route('admin.training.queue.destroy', $entry))
            ->assertForbidden();

        $this->assertFalse($this->teacherUser->can('create', TrainingQueueEntry::class));
        $this->assertTrue($this->teacherUser->can('addToQueue', TrainingQueueEntry::class));
    }

    /* O -------------------------------------------------------------- */
    public function test_the_admin_keeps_every_queue_control(): void
    {
        $this->addAs($this->teacherUser, $this->students['Ahmed']);
        Carbon::setTestNow(now()->addMinute());
        $this->addAs($this->teacherUser, $this->students['Mohamed']);

        $second = TrainingQueueEntry::where('student_id', $this->students['Mohamed']->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.training.queue.move', $second), ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(
            ['Mohamed', 'Ahmed'],
            collect($this->boards()->board(null)['queue'])->pluck('student')->take(2)->all(),
        );

        $this->actingAs($this->admin)
            ->post(route('admin.training.queue.store'), ['student_id' => $this->students['Hassan']->id])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->delete(route('admin.training.queue.destroy', $second))
            ->assertRedirect();

        $this->assertNotContains('Mohamed', collect($this->boards()->board(null)['queue'])->pluck('student')->all());
    }

    /* P -------------------------------------------------------------- */
    public function test_adding_announces_the_change_to_both_dashboards(): void
    {
        Event::fake([TrainingBoardChanged::class]);

        $this->addAs($this->teacherUser, $this->students['Ahmed']);

        Event::assertDispatched(TrainingBoardChanged::class);
    }

    /**
     * A teacher's own students are already in their line, enrolled from
     * ownership — adding them again says so rather than doubling them up.
     */
    public function test_an_owned_student_is_already_in_the_line(): void
    {
        $this->boards()->board($this->teacherUser);

        $this->addAs($this->teacherUser, $this->students['Ilyas'])
            ->assertSessionHasErrors(['student_id' => 'This student is already in the waiting queue.']);

        $this->assertSame(1, TrainingQueueEntry::where('student_id', $this->students['Ilyas']->id)->count());
    }

    /**
     * The dialog carries its own Alpine state in the page rather than reaching
     * into the compiled bundle, so a console served with an older build still
     * opens it. This is what broke it once: the button called a method the
     * bundle did not have yet, and clicking it did nothing at all.
     */
    public function test_the_add_student_dialog_does_not_depend_on_the_compiled_bundle(): void
    {
        $page = $this->actingAs($this->teacherUser)->get(route('instructor.training.index'));

        $html = $page->getContent();

        foreach (['adding:', 'openAdd()', 'matchingStudents', 'pickedName', 'selectDuration:'] as $piece) {
            $this->assertStringContainsString($piece, $html, "The page should define {$piece} itself.");
        }
    }

    /** A student already in today's line is shown as such and cannot be picked. */
    public function test_students_already_in_the_line_are_marked_in_the_dialog(): void
    {
        $this->addAs($this->teacherUser, $this->students['Ahmed']);

        $addable = $this->actingAs($this->teacherUser)
            ->get(route('instructor.training.index'))
            ->viewData('addable')
            ->keyBy('full_name');

        $this->assertSame(TrainingQueueEntry::WAITING, $addable['Ahmed']->queue_status);
        $this->assertNull($addable['Mohamed']->queue_status);

        $this->actingAs($this->teacherUser)
            ->get(route('instructor.training.index'))
            ->assertOk()
            ->assertSee('blocked_by', false);
    }

    /** The console hands the browser exactly the students it may queue. */
    public function test_the_console_offers_only_addable_students(): void
    {
        $page = $this->actingAs($this->teacherUser)->get(route('instructor.training.index'));

        $addable = $page->viewData('addable')->pluck('full_name')->all();

        $this->assertEqualsCanonicalizing(['Ahmed', 'Mohamed', 'Hassan', 'Ilyas'], $addable);
        $this->assertNotContains('Faadumo', $addable);
    }
}
