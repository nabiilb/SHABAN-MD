<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\LessonTopic;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $studentUser;

    private Student $ilyas;

    private Student $ahmed;

    private Instructor $xasan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $instructorUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Xasan Maxamuud']);
        $this->xasan = $this->makeInstructor('Xasan Maxamuud', $instructorUser);

        $this->studentUser = $this->makeUser(Role::STUDENT, ['name' => 'Ilyas']);
        $this->ilyas = $this->makeStudent('Ilyas', $this->xasan);
        $this->ilyas->forceFill(['user_id' => $this->studentUser->id])->save();

        $this->ahmed = $this->makeStudent('Ahmed', $this->xasan);
    }

    public function test_a_student_can_see_their_own_dashboard(): void
    {
        $this->actingAs($this->studentUser)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Ilyas');
    }

    public function test_a_student_sees_only_their_own_attendance(): void
    {
        Attendance::create([
            'student_id' => $this->ilyas->id,
            'instructor_id' => $this->xasan->id,
            'attendance_date' => Carbon::today(),
            'status' => 'present',
            'notes' => 'Ilyas own record',
        ]);

        Attendance::create([
            'student_id' => $this->ahmed->id,
            'instructor_id' => $this->xasan->id,
            'attendance_date' => Carbon::today(),
            'status' => 'present',
            'notes' => 'Ahmed private record',
        ]);

        $this->actingAs($this->studentUser)
            ->get(route('student.attendance'))
            ->assertOk()
            ->assertSee('Ilyas own record')
            ->assertDontSee('Ahmed private record');
    }

    public function test_a_student_sees_only_their_own_lessons(): void
    {
        $topic = LessonTopic::first();

        Lesson::create([
            'student_id' => $this->ilyas->id, 'instructor_id' => $this->xasan->id,
            'lesson_topic_id' => $topic->id, 'lesson_date' => Carbon::today(),
            'topic' => 'Ilyas lesson', 'duration_minutes' => 60, 'status' => 'completed',
        ]);

        Lesson::create([
            'student_id' => $this->ahmed->id, 'instructor_id' => $this->xasan->id,
            'lesson_topic_id' => $topic->id, 'lesson_date' => Carbon::today(),
            'topic' => 'Ahmed lesson', 'duration_minutes' => 60, 'status' => 'completed',
        ]);

        $this->actingAs($this->studentUser)
            ->get(route('student.lessons'))
            ->assertOk()
            ->assertSee('Ilyas lesson')
            ->assertDontSee('Ahmed lesson');
    }

    public static function forbiddenForStudents(): array
    {
        return [
            'admin dashboard' => ['admin.dashboard'],
            'admin students' => ['admin.students.index'],
            'instructor dashboard' => ['instructor.dashboard'],
            'instructor students' => ['instructor.students.index'],
            'company expenses' => ['admin.expenses.index'],
            'company debts' => ['admin.debts.index'],
            'audit logs' => ['admin.audit-logs.index'],
            'settings' => ['admin.settings.edit'],
        ];
    }

    #[DataProvider('forbiddenForStudents')]
    public function test_a_student_cannot_reach_admin_or_instructor_areas(string $route): void
    {
        $this->actingAs($this->studentUser)->get(route($route))->assertForbidden();
    }

    public function test_a_student_is_read_only_and_cannot_post_attendance(): void
    {
        $this->actingAs($this->studentUser)
            ->post(route('instructor.attendance.store'), [
                'student_id' => $this->ilyas->id,
                'attendance_date' => Carbon::today()->toDateString(),
                'status' => 'present',
            ])
            ->assertForbidden();

        $this->assertSame(0, Attendance::count());
    }

    public function test_a_user_with_the_student_role_but_no_student_record_is_blocked(): void
    {
        $orphan = $this->makeUser(Role::STUDENT);

        $this->actingAs($orphan)->get(route('student.dashboard'))->assertForbidden();
    }
}
