<?php

namespace Tests\Feature;

use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * An instructor account is not an account until it has a profile.
 *
 * /instructor/dashboard is guarded by the instructor.profile middleware, and
 * every isolation scope in the app keys off instructors.id — so an admin who
 * creates an instructor user and no profile has created a login that can reach
 * nothing. The two are now written together, in one transaction.
 */
class InstructorAccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $instructorRoleId;

    private int $studentRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-14 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->instructorRoleId = Role::where('name', Role::INSTRUCTOR)->value('id');
        $this->studentRoleId = Role::where('name', Role::STUDENT)->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createUser(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.users.store'), array_merge([
            'name' => 'Cabdi Yuusuf',
            'email' => 'cabdi@example.test',
            'phone' => '+252612345678',
            'role_id' => $this->instructorRoleId,
            'locale' => 'en',
            'is_active' => 1,
            'password' => 'Instructor@12345',
            'password_confirmation' => 'Instructor@12345',
        ], $overrides));
    }

    /* 1 + 2 + 3 ------------------------------------------------------- */
    public function test_creating_an_instructor_user_creates_exactly_one_linked_profile(): void
    {
        $this->createUser()->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'cabdi@example.test')->firstOrFail();

        $this->assertSame(1, Instructor::where('user_id', $user->id)->count());

        $profile = Instructor::where('user_id', $user->id)->firstOrFail();

        $this->assertSame($user->id, $profile->user_id);
        $this->assertSame('Cabdi Yuusuf', $profile->full_name);
        $this->assertSame('+252612345678', $profile->phone);
        $this->assertSame('cabdi@example.test', $profile->email);
        $this->assertSame('active', $profile->status);
        $this->assertMatchesRegularExpression('/^INS-\d{4}$/', $profile->instructor_number);
        $this->assertSame('2026-09-14', $profile->joining_date->toDateString());
    }

    /* 4 — and they can actually get in. */
    public function test_the_new_instructor_can_open_their_dashboard(): void
    {
        $this->createUser();

        $user = User::where('email', 'cabdi@example.test')->firstOrFail();

        $this->actingAs($user)->get(route('instructor.dashboard'))->assertOk();
        $this->actingAs($user)->get(route('instructor.training.index'))->assertOk();
        $this->assertNotNull($user->fresh()->instructorId());
    }

    /** Instructor numbers stay unique across several accounts. */
    public function test_each_profile_gets_its_own_number(): void
    {
        $this->createUser(['email' => 'one@example.test', 'name' => 'One']);
        $this->createUser(['email' => 'two@example.test', 'name' => 'Two']);
        $this->createUser(['email' => 'three@example.test', 'name' => 'Three']);

        $numbers = Instructor::orderBy('id')->pluck('instructor_number')->all();

        $this->assertSame(['INS-0001', 'INS-0002', 'INS-0003'], $numbers);
        $this->assertSame(3, count(array_unique($numbers)));
    }

    /* 5 — editing the account moves the profile with it. */
    public function test_editing_the_user_synchronises_the_profile(): void
    {
        $this->createUser();
        $user = User::where('email', 'cabdi@example.test')->firstOrFail();
        $profileId = $user->instructor->id;

        $this->actingAs($this->admin)->put(route('admin.users.update', $user), [
            'name' => 'Cabdi Yuusuf Xasan',
            'email' => 'cabdi.new@example.test',
            'phone' => '+252619999999',
            'role_id' => $this->instructorRoleId,
            'locale' => 'en',
            'is_active' => 1,
        ])->assertRedirect();

        $profile = Instructor::findOrFail($profileId);

        $this->assertSame('Cabdi Yuusuf Xasan', $profile->full_name);
        $this->assertSame('cabdi.new@example.test', $profile->email);
        $this->assertSame('+252619999999', $profile->phone);

        // Still exactly one — editing does not mint a second profile.
        $this->assertSame(1, Instructor::where('user_id', $user->id)->count());
    }

    /** A user promoted into the role gains the profile they now need. */
    public function test_promoting_a_user_to_instructor_creates_their_profile(): void
    {
        $this->createUser(['role_id' => $this->studentRoleId, 'email' => 'later@example.test']);

        $user = User::where('email', 'later@example.test')->firstOrFail();
        $this->assertSame(0, Instructor::where('user_id', $user->id)->count());

        $this->actingAs($this->admin)->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '+252612345678',
            'role_id' => $this->instructorRoleId,
            'locale' => 'en',
            'is_active' => 1,
        ])->assertRedirect();

        $this->assertSame(1, Instructor::where('user_id', $user->id)->count());
        $this->actingAs($user->fresh())->get(route('instructor.dashboard'))->assertOk();
    }

    /**
     * Taking the role away keeps the record — attendance, lessons and loans
     * all point at it — and stands the teacher down instead.
     */
    public function test_removing_the_role_deactivates_the_profile_rather_than_deleting_it(): void
    {
        $this->createUser();
        $user = User::where('email', 'cabdi@example.test')->firstOrFail();
        $profileId = $user->instructor->id;

        $this->actingAs($this->admin)->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role_id' => $this->studentRoleId,
            'locale' => 'en',
            'is_active' => 1,
        ])->assertRedirect();

        $profile = Instructor::findOrFail($profileId);

        $this->assertSame('inactive', $profile->status);
        $this->assertNull($profile->deleted_at);
        $this->assertSame(1, Instructor::count());
    }

    /* 13 — the guard itself is untouched. */
    public function test_an_instructor_without_a_profile_is_still_refused(): void
    {
        $orphan = $this->makeUser(Role::INSTRUCTOR, ['email' => 'orphan@example.test']);

        $this->actingAs($orphan)->get(route('instructor.dashboard'))->assertForbidden();
    }

    /* 6 + 7 + 8 — a student needs neither an email nor a birthday. */
    public function test_a_student_can_be_registered_without_an_email_or_a_birthday(): void
    {
        $base = [
            'full_name' => 'Abdirizak Hassan',
            'phone' => '+252613990001',
            'start_date' => today()->toDateString(),
            'required_training_days' => 24,
            'status' => 'active',
            'total_fee' => 120,
        ];

        foreach ([
            'neither' => [],
            'email only' => ['email' => 'a@example.test'],
            'birthday only' => ['date_of_birth' => '2000-01-01'],
        ] as $label => $extra) {
            $response = $this->actingAs($this->admin)->post(route('admin.students.store'), array_merge(
                $base,
                ['full_name' => $base['full_name'].' '.$label, 'phone' => $base['phone'].strlen($label)],
                $extra,
            ));

            $response->assertSessionHasNoErrors();
        }

        $student = Student::where('full_name', 'Abdirizak Hassan neither')->firstOrFail();

        $this->assertNull($student->email);
        $this->assertNull($student->date_of_birth);
        $this->assertSame(3, Student::count());
    }

    /** And the form does not mark either of them required. */
    public function test_the_student_form_does_not_demand_an_email_or_a_birthday(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.students.create'))->getContent();

        $this->assertMatchesRegularExpression('/<input id="email"(?![^>]*\brequired\b)[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<input id="date_of_birth"(?![^>]*\brequired\b)[^>]*>/', $html);
    }

    /* 10 — the assignment history stays correct. */
    public function test_assigning_a_student_opens_exactly_one_current_assignment(): void
    {
        $this->createUser();
        $instructor = Instructor::firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.students.store'), [
            'full_name' => 'Ilyas Maxamed',
            'phone' => '+252613990009',
            'start_date' => today()->toDateString(),
            'required_training_days' => 24,
            'status' => 'active',
            'current_instructor_id' => $instructor->id,
        ])->assertSessionHasNoErrors();

        $student = Student::where('full_name', 'Ilyas Maxamed')->firstOrFail();

        $this->assertSame(1, $student->assignments()->where('is_current', true)->count());
        $this->assertSame($instructor->id, $student->assignments()->where('is_current', true)->value('instructor_id'));

        // Reassigning closes the old row rather than leaving two current ones.
        $this->createUser(['email' => 'second@example.test', 'name' => 'Second']);
        $other = Instructor::where('email', 'second@example.test')->firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.students.update', $student), [
            'full_name' => $student->full_name,
            'phone' => $student->phone,
            'start_date' => $student->start_date->toDateString(),
            'required_training_days' => 24,
            'status' => 'active',
            'current_instructor_id' => $other->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $student->assignments()->where('is_current', true)->count());
        $this->assertSame($other->id, $student->assignments()->where('is_current', true)->value('instructor_id'));
        $this->assertSame(2, $student->assignments()->count());
    }
}
