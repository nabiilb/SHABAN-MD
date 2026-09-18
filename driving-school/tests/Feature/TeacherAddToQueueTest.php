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

        // Nothing enrols anybody: the line is empty until this teacher puts
        // somebody in it, and the only students they may put in it are the
        // ones they are responsible for.
        foreach (['Ahmed', 'Mohamed', 'Hassan', 'Ilyas'] as $name) {
            $this->students[$name] = $this->makeStudent($name, $this->teacher);
        }

        $this->students['Faadumo'] = $this->makeStudent('Faadumo', $this->other);
        $this->students['Cali'] = $this->makeStudent('Cali', null);
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

    /** @return array<int, array<string, mixed>> */
    private function searchResults(string $term): array
    {
        return $this->actingAs($this->teacherUser)
            ->getJson(route('instructor.training.students.search', ['q' => $term]))
            ->assertOk()
            ->json('results');
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

        // C + D — Ahmed is in the line, and he is the only one in it: the
        // teacher's other students are not enrolled behind his back.
        $this->assertSame(['Ahmed'], collect($board['queue'])->pluck('student')->all());
        $this->assertSame([1], collect($board['queue'])->pluck('display_position')->all());

        // F
        $this->assertSame(1, $board['stats']['waiting']);
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
        $this->assertSame(['Ahmed', 'Mohamed', 'Hassan'], $queue->pluck('student')->all());
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

    /**
     * K — the centre runs repeat training, so a finished student may rejoin the
     * same day once the twelve hours are up. The morning cycle is not reused:
     * the evening one is a new row, and both are still there afterwards.
     */
    public function test_a_completed_student_may_rejoin_the_same_day(): void
    {
        $this->addAs($this->teacherUser, $this->students['Ahmed']);
        $first = TrainingQueueEntry::where('student_id', $this->students['Ahmed']->id)->firstOrFail();

        $session = $this->sessions()->startNext($this->teacher, $this->teacherUser, ['assigned_duration_minutes' => 30]);
        $this->sessions()->end($session, $this->teacherUser);
        $this->sessions()->evaluate($session->fresh(), [
            'attendance_status' => 'present', 'evaluation' => 'good',
        ], $this->teacherUser);

        // 09:00 + 12h, still the same calendar day.
        Carbon::setTestNow(Carbon::parse('2026-09-13 21:00:00'));

        $this->addAs($this->teacherUser, $this->students['Ahmed'])->assertSessionHasNoErrors();

        $cycles = TrainingQueueEntry::where('student_id', $this->students['Ahmed']->id)
            ->orderBy('id')
            ->get();

        // Two cycles on one date, the first still saying what it was.
        $this->assertCount(2, $cycles);
        $this->assertSame(TrainingQueueEntry::COMPLETED, $cycles[0]->status);
        $this->assertTrue($cycles[0]->is($first));
        $this->assertSame('09:00', $cycles[0]->joined_at->format('H:i'));
        $this->assertSame(TrainingQueueEntry::WAITING, $cycles[1]->status);
        $this->assertSame('21:00', $cycles[1]->joined_at->format('H:i'));
        $this->assertSame(
            $cycles[0]->queue_date->toDateString(),
            $cycles[1]->queue_date->toDateString(),
        );

        // The finished session is still the day's history.
        $this->assertSame(1, TrainingSession::where('status', TrainingSession::COMPLETED)->count());
    }

    /* L -------------------------------------------------------------- */
    public function test_two_simultaneous_adds_cannot_create_two_entries(): void
    {
        $student = $this->students['Ahmed'];

        // The unique key on (active_student_id, queue_date) is the backstop
        // behind the lock, so bypass the service entirely and prove the
        // database refuses a second OPEN entry for the same student that day.
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
    public function test_only_a_teacher_or_admin_may_add_but_any_student_may_be_trained(): void
    {
        $student = $this->makeUser(Role::STUDENT);

        $this->addAs($student, $this->students['Ahmed'])->assertForbidden();

        // Being an instructor is the requirement; owning the student is not.
        // Faadumo belongs to the other teacher and may still be trained.
        $this->addAs($this->teacherUser, $this->students['Faadumo'])->assertSessionHasNoErrors();

        $entry = TrainingQueueEntry::where('student_id', $this->students['Faadumo']->id)->firstOrFail();

        $this->assertSame($this->teacher->id, $entry->preferred_instructor_id);
        $this->assertSame(
            $this->other->id,
            $this->students['Faadumo']->fresh()->current_instructor_id,
            'Training somebody is not taking them.',
        );
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
     * The teacher's own students are NOT in their line until they put them
     * there. Reading the board used to enrol every one of them; now the line is
     * whatever the teacher built, and an empty console is an honest one.
     */
    public function test_an_owned_student_is_not_in_the_line_until_they_are_added(): void
    {
        $this->boards()->board($this->teacherUser);

        $this->assertSame(0, TrainingQueueEntry::count());
        $this->assertSame([], $this->teacherQueueNames());

        $this->addAs($this->teacherUser, $this->students['Ilyas'])->assertSessionHasNoErrors();

        $this->assertSame(['Ilyas'], $this->teacherQueueNames());
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

        foreach (['adding:', 'openAdd()', 'searchStudents()', 'results:', 'pickedName', 'selectDuration:'] as $piece) {
            $this->assertStringContainsString($piece, $html, "The page should define {$piece} itself.");
        }
    }

    /** A student already in today's line is returned marked, not hidden. */
    public function test_students_already_in_the_line_are_marked_in_the_search(): void
    {
        $this->addAs($this->teacherUser, $this->students['Ahmed']);

        $waiting = $this->searchResults('Ahmed')[0];
        $free = $this->searchResults('Mohamed')[0];

        $this->assertFalse($waiting['eligible']);
        $this->assertSame('This student is already in the waiting queue.', $waiting['blocked_by']);
        $this->assertTrue($free['eligible']);
        $this->assertNull($free['blocked_by']);
    }

    /**
     * The search reaches the whole school — this teacher's students, the other
     * teacher's, and the one nobody is assigned to.
     */
    public function test_the_search_reaches_every_active_student(): void
    {
        foreach (['Ahmed', 'Mohamed', 'Hassan', 'Ilyas', 'Faadumo', 'Cali'] as $name) {
            $this->assertSame(
                [$name],
                array_column($this->searchResults($name), 'full_name'),
                "The search should find {$name}.",
            );
        }

        // Each row carries what the teacher needs in order to decide.
        $row = $this->searchResults('Faadumo')[0];

        $this->assertSame('Nasteexo', $row['permanent_instructor']);
        $this->assertArrayHasKey('remaining_days', $row);
        $this->assertArrayHasKey('status_label', $row);
        $this->assertTrue($row['eligible']);

        // A student with no instructor says so rather than showing a blank.
        $this->assertNull($this->searchResults('Cali')[0]['permanent_instructor']);
    }
}
