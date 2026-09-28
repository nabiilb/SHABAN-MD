<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\LessonTopic;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AttendanceAndLessonTest extends TestCase
{
    use RefreshDatabase;

    private User $instructorUser;

    private Instructor $instructor;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $this->instructorUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Xasan Maxamuud']);
        $this->instructor = $this->makeInstructor('Xasan Maxamuud', $this->instructorUser);
        $this->student = $this->makeStudent('Ilyas', $this->instructor);
    }

    private function checkIn(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->instructorUser)->post(route('instructor.attendance.store'), array_merge([
            'student_id' => $this->student->id,
            'attendance_date' => Carbon::today()->toDateString(),
            'check_in_time' => '09:00',
            'status' => 'present',
            // A present day must say which lesson was worked on.
            'lesson_topic_id' => LessonTopic::where('code', 'driving_practice')->value('id'),
        ], $overrides));
    }

    public function test_a_duplicate_check_in_on_the_same_day_is_rejected(): void
    {
        $this->checkIn()->assertRedirect();
        $this->checkIn()->assertSessionHasErrors('attendance_date');

        $this->assertSame(1, Attendance::count());
    }

    public function test_an_admin_can_switch_duplicate_check_ins_on(): void
    {
        $this->checkIn()->assertRedirect();

        Setting::put('allow_duplicate_attendance', '1');

        $this->checkIn()->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Attendance::count());
    }

    public function test_a_future_dated_check_in_is_rejected(): void
    {
        $this->checkIn(['attendance_date' => Carbon::tomorrow()->toDateString()])
            ->assertSessionHasErrors('attendance_date');

        $this->assertSame(0, Attendance::count());
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $this->checkIn(['status' => 'teleported'])->assertSessionHasErrors('status');
    }

    public function test_all_four_attendance_statuses_are_accepted(): void
    {
        foreach (Attendance::STATUSES as $index => $status) {
            $this->checkIn([
                'status' => $status,
                'attendance_date' => Carbon::today()->subDays($index + 1)->toDateString(),
                // Absent, excused and cancelled days have no lesson.
                'lesson_topic_id' => $status === 'present'
                    ? LessonTopic::where('code', 'driving_practice')->value('id')
                    : null,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(4, Attendance::count());
    }

    public function test_an_instructor_cannot_book_a_lesson_on_a_vehicle_that_is_not_his(): void
    {
        $otherInstructor = $this->makeInstructor('Nasteexo Aadan');
        $theirCar = $this->makeVehicle('AD-99', $otherInstructor);

        $this->actingAs($this->instructorUser)
            ->post(route('instructor.lessons.store'), [
                'student_id' => $this->student->id,
                'vehicle_id' => $theirCar->id,
                'lesson_topic_id' => LessonTopic::first()->id,
                'lesson_date' => Carbon::today()->toDateString(),
                'duration_minutes' => 60,
                'status' => 'completed',
            ])
            ->assertSessionHasErrors('vehicle_id');

        $this->assertSame(0, Lesson::count());
    }

    public function test_an_instructor_can_book_a_lesson_on_his_own_vehicle(): void
    {
        $myCar = $this->makeVehicle('AD-01', $this->instructor);

        $this->actingAs($this->instructorUser)
            ->post(route('instructor.lessons.store'), [
                'student_id' => $this->student->id,
                'vehicle_id' => $myCar->id,
                'lesson_topic_id' => LessonTopic::where('code', 'parking')->value('id'),
                'lesson_date' => Carbon::today()->toDateString(),
                'topic' => 'Reverse parking on a slope',
                'performance' => 'good',
                'duration_minutes' => 90,
                'status' => 'completed',
            ])
            ->assertRedirect(route('instructor.lessons.index'));

        $this->assertDatabaseHas('lessons', [
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'vehicle_id' => $myCar->id,
            'performance' => 'good',
            'duration_minutes' => 90,
        ]);
    }

    public function test_all_eleven_lesson_types_are_available(): void
    {
        $this->assertSame(11, LessonTopic::where('is_active', true)->count());

        foreach (['driving_practice', 'parking', 'reverse_driving', 'traffic_rules', 'road_signs',
            'road_practice', 'highway_driving', 'vehicle_control', 'safety',
            'final_assessment', 'other'] as $code) {
            $this->assertDatabaseHas('lesson_topics', ['code' => $code]);
        }
    }

    public function test_deleting_attendance_reduces_the_students_completed_days(): void
    {
        $this->checkIn(['attendance_date' => Carbon::today()->subDay()->toDateString()]);
        $this->checkIn();

        $this->assertSame(2, $this->student->fresh()->completed_days);

        $record = Attendance::latest('id')->first();

        $this->actingAs($this->instructorUser)
            ->delete(route('instructor.attendance.destroy', $record))
            ->assertRedirect();

        $this->assertSame(1, $this->student->fresh()->completed_days);
    }
}
