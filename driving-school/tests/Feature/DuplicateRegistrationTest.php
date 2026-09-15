<?php

namespace Tests\Feature;

use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A double-clicked New Student form must not register the same person twice.
 *
 * It very nearly did: the admin presses Register, the page hangs on a slow
 * connection, they press it again, and the school ends up with one student
 * entered twice — each copy holding its own perfectly valid initial payment, so
 * the day's income is overstated by the deposit and nobody notices until the
 * books are reconciled.
 *
 * The school identifies a student by their phone number: the register is kept
 * by it, and the import rejects a row without one and matches existing students
 * on it. So that is the key here too, normalised, and enforced by a unique
 * index rather than only by a rule the second request can pass while the first
 * is still in flight.
 */
class DuplicateRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Instructor $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->instructor = $this->makeInstructor('Xasan');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> the form exactly as the browser posts it. */
    private function form(array $overrides = []): array
    {
        return [
            'full_name' => 'Abdirizak HASSAN',
            'phone' => '+252611000111',
            'start_date' => today()->toDateString(),
            'required_training_days' => 24,
            'current_instructor_id' => $this->instructor->id,
            'status' => 'active',
            'total_fee' => 100,
            'amount_paid' => 40,
            'payment_method' => 'cash',
            ...$overrides,
        ];
    }

    private function register(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.students.store'), $this->form($overrides));
    }

    /* ================================================================
     | 9.1 + 9.4 + 9.5 — the same form, submitted twice
     | ================================================================ */

    public function test_submitting_the_same_form_twice_registers_one_student_and_one_payment(): void
    {
        $this->register()->assertRedirect();

        $second = $this->register();

        $second->assertInvalid(['phone' => 'already registered']);

        $this->assertSame(1, Student::count(), 'The second submit must not create a second student.');
        $this->assertSame(1, StudentPayment::count(), 'Nor a second initial payment.');
        $this->assertSame(40.0, app(DashboardService::class)->adminMetrics()['total_income']);
    }

    /** The message names who is already on the books, so it can be acted on. */
    public function test_the_refusal_names_the_student_already_registered(): void
    {
        $this->register()->assertRedirect();
        $existing = Student::firstOrFail();

        $this->register(['full_name' => 'Typed Again'])->assertInvalid([
            'phone' => 'Abdirizak HASSAN',
        ])->assertInvalid([
            'phone' => $existing->student_number,
        ]);
    }

    /* ================================================================
     | 9.2 — the same phone, written differently
     | ================================================================ */

    public function test_the_same_number_written_differently_is_the_same_student(): void
    {
        $this->register(['phone' => '+252611000111'])->assertRedirect();

        foreach (['0611000111', '252611000111', '611000111', '+252 611 000 111', '(252) 611-000-111'] as $variant) {
            $this->register(['full_name' => 'Someone Else', 'phone' => $variant])
                ->assertSessionHasErrors('phone');
        }

        $this->assertSame(1, Student::count());
        $this->assertSame(1, StudentPayment::count());
    }

    /** …and a genuinely different number is genuinely a different student. */
    public function test_a_different_number_registers_normally(): void
    {
        $this->register()->assertRedirect();
        $this->register(['full_name' => 'Maryan CIISE', 'phone' => '+252611000222'])->assertRedirect();

        $this->assertSame(2, Student::count());
        $this->assertSame(2, StudentPayment::count());
        $this->assertSame(80.0, app(DashboardService::class)->adminMetrics()['total_income']);
    }

    /* ================================================================
     | 9.3 — rapid duplicates, and the race the form rule cannot see
     | ================================================================ */

    public function test_five_rapid_submissions_produce_one_student(): void
    {
        $accepted = 0;

        foreach (range(1, 5) as $_) {
            if ($this->register()->isRedirect() && session('errors') === null) {
                $accepted++;
            }

            $this->flushSession();
        }

        $this->assertSame(1, $accepted, 'Exactly one of five identical submissions may be accepted.');
        $this->assertSame(1, Student::count());
        $this->assertSame(1, StudentPayment::count());
    }

    /**
     * The case the validation rule cannot catch: two requests that both
     * validated before either committed. The rule is bypassed entirely here —
     * writing straight through the model, as the second request would once it
     * had passed a check made a moment too early — and the database still
     * refuses it.
     */
    public function test_the_database_itself_refuses_a_second_active_student_on_one_number(): void
    {
        $this->register()->assertRedirect();

        try {
            Student::create([
                'student_number' => 'STD-9999',
                'full_name' => 'Race Winner',
                // The same number, written the other way — normalisation means
                // the index still sees it.
                'phone' => '0611000111',
                'start_date' => today(),
                'required_training_days' => 24,
                'status' => 'active',
                'total_fee' => 100,
            ]);
            $this->fail('The database allowed two active students to share a phone number.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage());
            $this->assertStringContainsString('students_active_phone_unique', $e->getMessage());
        }

        $this->assertSame(1, Student::count());
    }

    /** And when it does fire during a registration, the admin sees the message. */
    public function test_a_guard_collision_during_registration_reads_as_a_validation_error(): void
    {
        $this->register()->assertRedirect();

        // Nothing holds the key as far as validation can tell…
        Student::firstOrFail()->forceFill(['active_phone_key' => null])->saveQuietly();
        // …but the index still does, because the first student's row is there.
        Student::query()->whereKey(Student::value('id'))->update(['active_phone_key' => '+252611000111']);

        $this->register(['full_name' => 'Race Loser'])->assertSessionHasErrors('phone');

        $this->assertSame(1, Student::count(), 'The losing request wrote nothing at all.');
        $this->assertSame(1, StudentPayment::count());
    }

    /* ================================================================
     | 9.6 — a failed first attempt can be retried
     | ================================================================ */

    public function test_a_registration_that_failed_can_be_retried_immediately(): void
    {
        // A rejected attempt: no instructor by that id.
        $this->register(['current_instructor_id' => 9999])->assertSessionHasErrors('current_instructor_id');

        $this->assertSame(0, Student::count(), 'The failed attempt left nothing behind.');

        // The number is free, so the corrected form goes through.
        $this->register()->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Student::count());
        $this->assertSame(1, StudentPayment::count());
        $this->assertSame(60.0, Student::firstOrFail()->balance);
    }

    /** A student who has left frees their number for a genuine re-registration. */
    public function test_a_removed_student_releases_their_number(): void
    {
        $this->register()->assertRedirect();
        $first = Student::firstOrFail();

        $first->delete();

        $this->register(['full_name' => 'Abdirizak HASSAN'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Student::count());
        $this->assertSame(2, Student::withTrashed()->count(), 'The old record is still on file.');
        $this->assertNull($first->fresh()->active_phone_key);
    }

    /** As does one who completed or cancelled — they are no longer an active student. */
    public function test_a_completed_student_releases_their_number(): void
    {
        $this->register()->assertRedirect();
        $first = Student::firstOrFail();

        $first->update(['status' => 'completed']);
        $this->assertNull($first->fresh()->active_phone_key);

        $this->register(['full_name' => 'Abdirizak HASSAN'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Student::count());
        $this->assertSame('completed', $first->fresh()->status);
    }

    /* ================================================================
     | 9.5 + 9.7 — nothing is merged, nothing existing is touched
     | ================================================================ */

    /** Two genuinely different people are never folded together. */
    public function test_two_different_students_are_never_merged(): void
    {
        $this->register(['full_name' => 'Abdirizak HASSAN', 'phone' => '+252611000111'])->assertRedirect();
        $this->register(['full_name' => 'Maryan CIISE', 'phone' => '+252611000222'])->assertRedirect();

        $this->assertSame(
            ['Abdirizak HASSAN', 'Maryan CIISE'],
            Student::orderBy('id')->pluck('full_name')->all(),
        );
        $this->assertCount(2, Student::pluck('student_number')->unique());
        $this->assertSame(2, StudentPayment::count());
    }

    /** A refused duplicate leaves the student already registered exactly as they were. */
    public function test_a_refused_duplicate_changes_nothing_about_the_original(): void
    {
        $this->register()->assertRedirect();

        $before = Student::firstOrFail()->getAttributes();
        $paymentBefore = StudentPayment::firstOrFail()->getAttributes();

        $this->register(['full_name' => 'Different Name', 'total_fee' => 999, 'amount_paid' => 500]);

        $this->assertSame($before, Student::firstOrFail()->fresh()->getAttributes());
        $this->assertSame($paymentBefore, StudentPayment::firstOrFail()->fresh()->getAttributes());
    }

    /**
     * 9.7 — students already on the books, including any imported pair who
     * genuinely share a number, are not edited, merged or blocked from being
     * saved. The guard declines to claim a key another active student holds, so
     * an ordinary edit of such a row still works.
     */
    public function test_existing_students_sharing_a_number_stay_editable_and_unmerged(): void
    {
        $first = $this->makeStudent('Imported One', $this->instructor, ['phone' => '+252611000333']);

        // A pair the guard cannot both hold, in exactly the state the
        // migration's backfill leaves them: the later one keeps its number and
        // holds no key. Written past the model, because the model is what stops
        // this being created in the first place.
        $second = $this->makeStudent('Imported Two', $this->instructor);
        Student::query()->whereKey($second->id)->update([
            'phone' => '+252611000333',
            'active_phone_key' => null,
        ]);
        $second->refresh();

        $this->assertSame('+252611000333', $first->fresh()->active_phone_key);
        $this->assertNull($second->fresh()->active_phone_key, 'The later one does not hold the key.');

        // Both are still there, and the second can still be edited.
        $second->update(['address' => 'Hodan, Muqdisho']);

        $this->assertSame(2, Student::count());
        $this->assertSame('Hodan, Muqdisho', $second->fresh()->address);
        $this->assertSame('Imported One', $first->fresh()->full_name);
        $this->assertSame('+252611000333', $second->fresh()->phone, 'Their number is not rewritten.');
    }

    /** Editing a student does not trip the rule against themselves. */
    public function test_a_student_can_be_edited_without_colliding_with_their_own_number(): void
    {
        $this->register()->assertRedirect();
        $student = Student::firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.students.update', $student), [
            'full_name' => 'Abdirizak HASSAN CILMI',
            'phone' => '0611000111',
            'start_date' => $student->start_date->toDateString(),
            'required_training_days' => 24,
            'current_instructor_id' => $this->instructor->id,
            'status' => 'active',
            'total_fee' => 120,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Abdirizak HASSAN CILMI', $student->fresh()->full_name);
        $this->assertSame('0611000111', $student->fresh()->phone, 'What was typed is what is stored.');
        $this->assertSame('+252611000111', $student->fresh()->active_phone_key);
        $this->assertSame(1, StudentPayment::count(), 'Editing still records no payment.');
    }

    /** But it cannot be edited onto somebody else's number. */
    public function test_a_student_cannot_be_edited_onto_another_active_students_number(): void
    {
        $this->register()->assertRedirect();
        $other = $this->makeStudent('Maryan CIISE', $this->instructor, ['phone' => '+252611000444']);

        $this->actingAs($this->admin)->put(route('admin.students.update', $other), [
            'full_name' => 'Maryan CIISE',
            'phone' => '+252611000111',
            'start_date' => $other->start_date->toDateString(),
            'required_training_days' => 24,
            'status' => 'active',
            'total_fee' => 0,
        ])->assertSessionHasErrors('phone');

        $this->assertSame('+252611000444', $other->fresh()->phone);
    }

    /* ================================================================
     | The normalisation the whole rule rests on
     | ================================================================ */

    public function test_every_way_of_writing_one_number_normalises_to_the_same_key(): void
    {
        foreach (['0611000111', '611000111', '252611000111', '+252611000111', '+252 611 000 111', '(252) 611-000-111'] as $written) {
            $this->assertSame('+252611000111', PhoneNumber::normalize($written), "Failed on: {$written}");
        }

        $this->assertNull(PhoneNumber::normalize(null));
        $this->assertNull(PhoneNumber::normalize(''));
        $this->assertNull(PhoneNumber::normalize('no digits here'));
        $this->assertTrue(PhoneNumber::same('0611000111', '+252611000111'));
        $this->assertFalse(PhoneNumber::same('0611000111', '+252611000222'));
        $this->assertFalse(PhoneNumber::same(null, null), 'Two unknowns are not the same person.');
    }

    /**
     * The migration, run against a database that already holds a duplicate
     * pair, and then run again.
     *
     * This is the only part of the change that touches a production table, so
     * prove it before anybody runs it there: both students survive, neither is
     * edited or merged, the first keeps the key, the later one holds none, and
     * a second pass changes nothing.
     */
    public function test_the_phone_guard_migration_grandfathers_duplicates_and_is_idempotent(): void
    {
        $first = $this->makeStudent('Imported One', $this->instructor, ['phone' => '+252611000555']);
        $second = $this->makeStudent('Imported Two', $this->instructor);

        // A legacy pair, written past the model that would now prevent it.
        Student::query()->whereKey($second->id)->update([
            'phone' => '0611000555',
            'active_phone_key' => null,
        ]);

        $migration = require database_path('migrations/2026_09_15_000100_add_active_phone_guard_to_students.php');

        ob_start();
        $migration->up();
        $migration->up();
        $output = (string) ob_get_clean();

        // Both are still there, untouched, with their own numbers.
        $this->assertSame(2, Student::count());
        $this->assertSame('+252611000555', $first->fresh()->phone);
        $this->assertSame('0611000555', $second->fresh()->phone);
        $this->assertSame('Imported One', $first->fresh()->full_name);
        $this->assertSame('Imported Two', $second->fresh()->full_name);

        // The older one holds the key; the later one holds none.
        $this->assertSame('+252611000555', $first->fresh()->active_phone_key);
        $this->assertNull($second->fresh()->active_phone_key);

        // And the migration said so rather than failing or fixing it silently.
        $this->assertStringContainsString('sharing a phone number', $output);
        $this->assertStringContainsString('Imported Two', $output);

        // The guard is still a guarantee afterwards.
        $this->register(['phone' => '+252611000555'])->assertInvalid(['phone' => 'already registered']);
        $this->assertSame(2, Student::count());
    }

    /** The form disables its own button, as comfort on top of the real rule. */
    public function test_the_form_prevents_a_second_click(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.students.create'));

        $html = $page->assertOk()->getContent();

        foreach (['submitting: false', 'submitOnce($event)', ':disabled="submitting"', 'Registering'] as $piece) {
            $this->assertStringContainsString($piece, $html, "The form should define {$piece}.");
        }
    }
}
