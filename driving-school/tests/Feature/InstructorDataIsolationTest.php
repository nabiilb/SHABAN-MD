<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\InstructorLoan;
use App\Models\Lesson;
use App\Models\LessonTopic;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The mandatory security scenario from the specification.
 *
 *   Xasan Maxamuud  → Ilyas, Maryan
 *   Nasteexo Aadan  → Ahmed, Fatima
 *
 * Xasan must never reach Nasteexo's records, whatever id he puts in the URL.
 */
class InstructorDataIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $xasanUser;

    private User $nasteexoUser;

    private Instructor $xasan;

    private Instructor $nasteexo;

    private Student $ilyas;

    private Student $maryan;

    private Student $ahmed;

    private Student $fatima;

    private Vehicle $xasanCar;

    private Vehicle $nasteexoCar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $this->xasanUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Xasan Maxamuud', 'email' => 'xasan@test.local']);
        $this->nasteexoUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Nasteexo Aadan', 'email' => 'nasteexo@test.local']);

        $this->xasan = $this->makeInstructor('Xasan Maxamuud', $this->xasanUser);
        $this->nasteexo = $this->makeInstructor('Nasteexo Aadan', $this->nasteexoUser);

        $this->ilyas = $this->makeStudent('Ilyas', $this->xasan);
        $this->maryan = $this->makeStudent('Maryan Cabdi Faarax', $this->xasan);
        $this->ahmed = $this->makeStudent('Ahmed', $this->nasteexo);
        $this->fatima = $this->makeStudent('Fatima', $this->nasteexo);

        $this->xasanCar = $this->makeVehicle('AD-01', $this->xasan);
        $this->nasteexoCar = $this->makeVehicle('AD-02', $this->nasteexo);
    }

    /* ----------------------------------------------------------------
     | Listing
     | ---------------------------------------------------------------- */

    public function test_an_instructor_sees_only_his_own_students_in_the_list(): void
    {
        $this->actingAs($this->xasanUser)
            ->get(route('instructor.students.index'))
            ->assertOk()
            ->assertSee('Ilyas')
            ->assertSee('Maryan Cabdi Faarax')
            ->assertDontSee('Ahmed')
            ->assertDontSee('Fatima');

        $this->actingAs($this->nasteexoUser)
            ->get(route('instructor.students.index'))
            ->assertOk()
            ->assertSee('Ahmed')
            ->assertSee('Fatima')
            ->assertDontSee('Ilyas')
            ->assertDontSee('Maryan Cabdi Faarax');
    }

    public function test_the_dashboard_counts_only_the_instructors_own_students(): void
    {
        $this->actingAs($this->xasanUser)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('My Dashboard')
            ->assertSee('Ilyas')
            ->assertDontSee('Ahmed');
    }

    /* ----------------------------------------------------------------
     | Direct URL access — changing the id must not work
     | ---------------------------------------------------------------- */

    public function test_an_instructor_cannot_open_another_instructors_student_by_id(): void
    {
        $this->actingAs($this->xasanUser)
            ->get(route('instructor.students.show', $this->ahmed))
            ->assertForbidden();

        $this->actingAs($this->nasteexoUser)
            ->get(route('instructor.students.show', $this->ilyas))
            ->assertForbidden();
    }

    public function test_an_instructor_can_open_his_own_student(): void
    {
        $this->actingAs($this->xasanUser)
            ->get(route('instructor.students.show', $this->ilyas))
            ->assertOk()
            ->assertSee('Ilyas');
    }

    public function test_an_instructor_cannot_check_in_another_instructors_student(): void
    {
        $this->actingAs($this->xasanUser)
            ->post(route('instructor.attendance.store'), [
                'student_id' => $this->ahmed->id,
                'attendance_date' => Carbon::today()->toDateString(),
                'status' => 'present',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('attendance', 0);
    }

    public function test_an_instructor_can_check_in_his_own_student(): void
    {
        $this->actingAs($this->xasanUser)
            ->post(route('instructor.attendance.store'), [
                'student_id' => $this->ilyas->id,
                'attendance_date' => Carbon::today()->toDateString(),
                'check_in_time' => '09:00',
                'status' => 'present',
                'lesson_topic_id' => LessonTopic::where('code', 'driving_practice')->value('id'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('attendance', [
            'student_id' => $this->ilyas->id,
            // Derived from the authenticated user, not from the request.
            'instructor_id' => $this->xasan->id,
            'status' => 'present',
        ]);
    }

    public function test_the_instructor_id_cannot_be_spoofed_through_the_request(): void
    {
        $this->actingAs($this->xasanUser)
            ->post(route('instructor.attendance.store'), [
                'student_id' => $this->ilyas->id,
                'instructor_id' => $this->nasteexo->id, // ignored
                'attendance_date' => Carbon::today()->toDateString(),
                'status' => 'present',
                'lesson_topic_id' => LessonTopic::where('code', 'driving_practice')->value('id'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('attendance', [
            'student_id' => $this->ilyas->id,
            'instructor_id' => $this->xasan->id,
        ]);
    }

    public function test_an_instructor_cannot_add_a_lesson_for_another_instructors_student(): void
    {
        $topic = LessonTopic::first();

        $this->actingAs($this->xasanUser)
            ->post(route('instructor.lessons.store'), [
                'student_id' => $this->fatima->id,
                'lesson_topic_id' => $topic->id,
                'lesson_date' => Carbon::today()->toDateString(),
                'duration_minutes' => 60,
                'status' => 'completed',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_an_instructor_cannot_read_or_edit_another_instructors_attendance(): void
    {
        $foreign = Attendance::create([
            'student_id' => $this->ahmed->id,
            'instructor_id' => $this->nasteexo->id,
            'attendance_date' => Carbon::today(),
            'status' => 'present',
        ]);

        $this->actingAs($this->xasanUser)
            ->get(route('instructor.attendance.edit', $foreign))
            ->assertForbidden();

        $this->actingAs($this->xasanUser)
            ->delete(route('instructor.attendance.destroy', $foreign))
            ->assertForbidden();

        $this->assertDatabaseHas('attendance', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    public function test_an_instructor_cannot_read_or_edit_another_instructors_lesson(): void
    {
        $foreign = Lesson::create([
            'student_id' => $this->ahmed->id,
            'instructor_id' => $this->nasteexo->id,
            'lesson_topic_id' => LessonTopic::first()->id,
            'lesson_date' => Carbon::today(),
            'duration_minutes' => 60,
            'status' => 'completed',
        ]);

        $this->actingAs($this->xasanUser)->get(route('instructor.lessons.show', $foreign))->assertForbidden();
        $this->actingAs($this->xasanUser)->get(route('instructor.lessons.edit', $foreign))->assertForbidden();
    }

    public function test_an_instructor_sees_only_his_own_vehicles(): void
    {
        $this->actingAs($this->xasanUser)
            ->get(route('instructor.vehicles.index'))
            ->assertOk()
            ->assertSee('AD-01')
            ->assertDontSee('AD-02');

        $this->actingAs($this->xasanUser)
            ->get(route('instructor.vehicles.show', $this->nasteexoCar))
            ->assertForbidden();
    }

    public function test_an_instructor_sees_only_his_own_loan(): void
    {
        $mine = InstructorLoan::create([
            'loan_number' => 'LOAN-0001',
            'instructor_id' => $this->xasan->id,
            'amount' => 300,
            'remaining_amount' => 150,
            'loan_date' => Carbon::today()->subMonth(),
            'status' => 'partially_paid',
        ]);

        $theirs = InstructorLoan::create([
            'loan_number' => 'LOAN-0002',
            'instructor_id' => $this->nasteexo->id,
            'amount' => 900,
            'remaining_amount' => 900,
            'loan_date' => Carbon::today()->subMonth(),
            'status' => 'outstanding',
        ]);

        $this->actingAs($this->xasanUser)
            ->get(route('instructor.loans.index'))
            ->assertOk()
            ->assertSee('LOAN-0001')
            ->assertDontSee('LOAN-0002');

        $this->actingAs($this->xasanUser)->get(route('instructor.loans.show', $theirs))->assertForbidden();
        $this->actingAs($this->xasanUser)->get(route('instructor.loans.show', $mine))->assertOk();
    }

    /* ----------------------------------------------------------------
     | Admin-only areas
     | ---------------------------------------------------------------- */

    public static function adminOnlyRoutes(): array
    {
        return [
            'admin dashboard' => ['admin.dashboard'],
            'company expenses' => ['admin.expenses.index'],
            'company debts' => ['admin.debts.index'],
            'debt payments' => ['admin.debt-payments.index'],
            'suppliers' => ['admin.suppliers.index'],
            'fuel' => ['admin.fuel.index'],
            'admin loans' => ['admin.loans.index'],
            'student payments' => ['admin.student-payments.index'],
            'users' => ['admin.users.index'],
            'audit logs' => ['admin.audit-logs.index'],
            'settings' => ['admin.settings.edit'],
            'admin reports' => ['admin.reports.index'],
            'admin students' => ['admin.students.index'],
            'admin instructors' => ['admin.instructors.index'],
        ];
    }

    #[DataProvider('adminOnlyRoutes')]
    public function test_an_instructor_is_blocked_from_every_admin_route(string $route): void
    {
        $this->actingAs($this->xasanUser)->get(route($route))->assertForbidden();
    }

    public function test_the_instructor_navigation_never_links_to_company_finance(): void
    {
        $response = $this->actingAs($this->xasanUser)->get(route('instructor.dashboard'));

        $response->assertOk();
        $response->assertDontSee('Total Income');
        $response->assertDontSee('Net Profit');
        $response->assertDontSee('Company Debts');
        $response->assertDontSee('Audit Logs');
        $response->assertDontSee(route('admin.dashboard'));
    }
}
