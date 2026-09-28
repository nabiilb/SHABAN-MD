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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The lesson worked on a day, and the rating for it, belong to that day's
 * attendance record — not to the student's profile or their permanent
 * instructor. A hand-over only decides who owns the day.
 *
 *   08/09  Teacher A  Parking          Excellent
 *   09/09  Teacher B  Highway Driving  Good        (handed over on the day)
 *   10/09  Teacher A  City Driving     Very Good   (back automatically)
 */
class DailyLessonAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherAUser;

    private User $teacherBUser;

    private Instructor $teacherA;

    private Instructor $teacherB;

    private Student $ahmed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-08 09:00:00'));

        $this->teacherAUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Teacher A']);
        $this->teacherBUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Teacher B']);
        $this->teacherA = $this->makeInstructor('Teacher A', $this->teacherAUser);
        $this->teacherB = $this->makeInstructor('Teacher B', $this->teacherBUser);

        $this->ahmed = $this->makeStudent('Ahmed', $this->teacherA, [
            'start_date' => Carbon::parse('2026-08-01'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function topic(string $code): int
    {
        return LessonTopic::where('code', $code)->value('id');
    }

    /** Marks Ahmed present, recording the day's lesson and rating with it. */
    private function checkIn(User $actor, string $date, string $topicCode, ?string $performance): TestResponse
    {
        return $this->actingAs($actor->fresh())->post(route('instructor.attendance.store'), [
            'student_id' => $this->ahmed->id,
            'attendance_date' => $date,
            'check_in_time' => '09:00',
            'status' => 'present',
            'lesson_topic_id' => $this->topic($topicCode),
            'performance' => $performance,
        ]);
    }

    private function dayFor(string $date): ?Attendance
    {
        return Attendance::with('lesson.lessonTopic')
            ->where('student_id', $this->ahmed->id)
            ->whereDate('attendance_date', $date)
            ->first();
    }

    /* ----------------------------------------------------------------
     | The lesson is part of the day's attendance
     | ---------------------------------------------------------------- */

    public function test_marking_a_student_present_records_the_days_lesson_and_rating(): void
    {
        $this->checkIn($this->teacherAUser, '2026-09-08', 'parking', 'excellent')->assertRedirect();

        $day = $this->dayFor('2026-09-08');

        $this->assertNotNull($day->lesson_id, 'The lesson hangs off the attendance record.');
        $this->assertSame('Parking', $day->lesson->lessonTopic->name);
        $this->assertSame('excellent', $day->lesson->performance);
        $this->assertSame($this->teacherA->id, $day->lesson->instructor_id);
        $this->assertSame('2026-09-08', $day->lesson->lesson_date->toDateString());
    }

    public function test_a_present_day_must_say_which_lesson_was_worked_on(): void
    {
        $this->actingAs($this->teacherAUser)
            ->post(route('instructor.attendance.store'), [
                'student_id' => $this->ahmed->id,
                'attendance_date' => '2026-09-08',
                'status' => 'present',
            ])
            ->assertSessionHasErrors('lesson_topic_id');

        $this->assertSame(0, Attendance::count());
    }

    public function test_an_absence_needs_no_lesson(): void
    {
        $this->actingAs($this->teacherAUser)
            ->post(route('instructor.attendance.store'), [
                'student_id' => $this->ahmed->id,
                'attendance_date' => '2026-09-08',
                'status' => 'absent',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull($this->dayFor('2026-09-08')->lesson_id);
        $this->assertSame(0, Lesson::count());
    }

    public function test_editing_a_day_updates_the_same_lesson_without_duplicating_it(): void
    {
        $this->checkIn($this->teacherAUser, '2026-09-08', 'parking', 'excellent');

        $day = $this->dayFor('2026-09-08');
        $lessonId = $day->lesson_id;

        $this->actingAs($this->teacherAUser->fresh())
            ->put(route('instructor.attendance.update', $day), [
                'student_id' => $this->ahmed->id,
                'attendance_date' => '2026-09-08',
                'check_in_time' => '09:00',
                'status' => 'present',
                'lesson_topic_id' => $this->topic('reverse_driving'),
                'performance' => 'good',
            ])
            ->assertRedirect();

        $day->refresh()->load('lesson.lessonTopic');

        $this->assertSame(1, Lesson::count(), 'The same lesson row is reused.');
        $this->assertSame($lessonId, $day->lesson_id);
        $this->assertSame('Reverse Driving', $day->lesson->lessonTopic->name);
        $this->assertSame('good', $day->lesson->performance);
    }

    public function test_switching_a_day_to_absent_clears_its_lesson(): void
    {
        $this->checkIn($this->teacherAUser, '2026-09-08', 'parking', 'excellent');
        $day = $this->dayFor('2026-09-08');

        $this->actingAs($this->teacherAUser->fresh())
            ->put(route('instructor.attendance.update', $day), [
                'student_id' => $this->ahmed->id,
                'attendance_date' => '2026-09-08',
                'status' => 'absent',
            ])
            ->assertRedirect();

        $this->assertNull($day->fresh()->lesson_id);
        $this->assertSame(0, Lesson::count());
    }

    /* ----------------------------------------------------------------
     | The full three-day flow from the specification
     | ---------------------------------------------------------------- */

    public function test_the_three_day_flow_with_a_hand_over_in_the_middle(): void
    {
        // 08/09 — Teacher A, Parking, Excellent
        $this->checkIn($this->teacherAUser, '2026-09-08', 'parking', 'excellent')->assertRedirect();

        // 09/09 — Teacher A hands the day to Teacher B before checking in.
        Carbon::setTestNow(Carbon::parse('2026-09-09 08:30:00'));

        $this->actingAs($this->teacherAUser->fresh())
            ->post(route('instructor.attendance.transfer'), [
                'student_id' => $this->ahmed->id,
                'to_instructor_id' => $this->teacherB->id,
                'reason' => 'Instructor unavailable',
            ])
            ->assertRedirect();

        // Teacher B marks him present with the day's lesson.
        $this->checkIn($this->teacherBUser, '2026-09-09', 'highway_driving', 'good')->assertRedirect();

        // 10/09 — no hand-over, so he is back with Teacher A automatically.
        Carbon::setTestNow(Carbon::parse('2026-09-10 08:30:00'));
        $this->checkIn($this->teacherAUser, '2026-09-10', 'road_practice', 'very_good')->assertRedirect();

        // The three days stand independently.
        $expected = [
            ['2026-09-08', $this->teacherA->id, 'Parking', 'excellent'],
            ['2026-09-09', $this->teacherB->id, 'Highway Driving', 'good'],
            ['2026-09-10', $this->teacherA->id, 'Road Practice', 'very_good'],
        ];

        foreach ($expected as [$date, $instructorId, $lessonName, $performance]) {
            $day = $this->dayFor($date);

            $this->assertNotNull($day, "Missing attendance for {$date}.");
            $this->assertSame($instructorId, $day->instructor_id, "Wrong instructor on {$date}.");
            $this->assertSame($lessonName, $day->lesson->lessonTopic->name, "Wrong lesson on {$date}.");
            $this->assertSame($performance, $day->lesson->performance, "Wrong rating on {$date}.");
            $this->assertSame($instructorId, $day->lesson->instructor_id, "Lesson owner wrong on {$date}.");
        }

        $this->assertSame(3, Attendance::count());
        $this->assertSame(3, Lesson::count());

        // The hand-over never touched the permanent assignment.
        $this->assertSame($this->teacherA->id, $this->ahmed->fresh()->current_instructor_id);
        $this->assertDatabaseCount('attendance_transfers', 1);
    }

    public function test_the_student_returns_to_the_permanent_instructor_the_next_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 08:30:00'));

        $this->actingAs($this->teacherAUser->fresh())
            ->post(route('instructor.attendance.transfer'), [
                'student_id' => $this->ahmed->id,
                'to_instructor_id' => $this->teacherB->id,
            ])
            ->assertRedirect();

        // On the day itself he is Teacher B's.
        $this->assertSame($this->teacherB->id, $this->ahmed->fresh()->instructorIdOn('2026-09-09'));
        $this->actingAs($this->teacherBUser->fresh())
            ->get(route('instructor.attendance.create'))->assertOk()->assertSee('Ahmed');
        $this->actingAs($this->teacherAUser->fresh())
            ->get(route('instructor.attendance.create'))->assertOk()->assertDontSee('Ahmed');

        // The next day he is back on Teacher A's list, with nothing to undo.
        Carbon::setTestNow(Carbon::parse('2026-09-10 08:30:00'));

        $this->assertSame($this->teacherA->id, $this->ahmed->fresh()->instructorIdOn('2026-09-10'));
        $this->actingAs($this->teacherAUser->fresh())
            ->get(route('instructor.attendance.create'))->assertOk()->assertSee('Ahmed');
        $this->actingAs($this->teacherBUser->fresh())
            ->get(route('instructor.attendance.create'))->assertOk()->assertDontSee('Ahmed');
    }

    public function test_a_hand_over_carries_an_already_recorded_lesson_across(): void
    {
        // Teacher A checks him in first, then hands the day over.
        Carbon::setTestNow(Carbon::parse('2026-09-09 08:30:00'));
        $this->checkIn($this->teacherAUser, '2026-09-09', 'highway_driving', 'good');

        $lessonId = $this->dayFor('2026-09-09')->lesson_id;

        $this->actingAs($this->teacherAUser->fresh())
            ->post(route('instructor.attendance.transfer'), [
                'student_id' => $this->ahmed->id,
                'to_instructor_id' => $this->teacherB->id,
            ])
            ->assertRedirect();

        $day = $this->dayFor('2026-09-09');

        // Same lesson row, same rating, now owned by Teacher B.
        $this->assertSame($lessonId, $day->lesson_id);
        $this->assertSame($this->teacherB->id, $day->instructor_id);
        $this->assertSame($this->teacherB->id, $day->lesson->instructor_id);
        $this->assertSame('Highway Driving', $day->lesson->lessonTopic->name);
        $this->assertSame('good', $day->lesson->performance);
        $this->assertSame(1, Lesson::count(), 'Nothing was duplicated.');
    }

    public function test_the_previous_days_lesson_and_rating_are_untouched_by_a_hand_over(): void
    {
        $this->checkIn($this->teacherAUser, '2026-09-08', 'parking', 'excellent');
        $before = $this->dayFor('2026-09-08')->lesson->only(['id', 'instructor_id', 'lesson_topic_id', 'performance']);

        Carbon::setTestNow(Carbon::parse('2026-09-09 08:30:00'));
        $this->actingAs($this->teacherAUser->fresh())
            ->post(route('instructor.attendance.transfer'), [
                'student_id' => $this->ahmed->id,
                'to_instructor_id' => $this->teacherB->id,
            ]);
        $this->checkIn($this->teacherBUser, '2026-09-09', 'highway_driving', 'good');

        $after = $this->dayFor('2026-09-08')->lesson->only(['id', 'instructor_id', 'lesson_topic_id', 'performance']);

        $this->assertSame($before, $after, "Yesterday's lesson and rating must not change.");
    }

    /* ----------------------------------------------------------------
     | Who can see which day's lesson
     | ---------------------------------------------------------------- */

    public function test_each_instructor_sees_only_the_lessons_they_taught(): void
    {
        $this->checkIn($this->teacherAUser, '2026-09-08', 'parking', 'excellent');

        Carbon::setTestNow(Carbon::parse('2026-09-09 08:30:00'));
        $this->actingAs($this->teacherAUser->fresh())
            ->post(route('instructor.attendance.transfer'), [
                'student_id' => $this->ahmed->id,
                'to_instructor_id' => $this->teacherB->id,
            ]);
        $this->checkIn($this->teacherBUser, '2026-09-09', 'highway_driving', 'good');

        $forA = Lesson::visibleTo($this->teacherAUser->fresh())->with('lessonTopic')->get();
        $forB = Lesson::visibleTo($this->teacherBUser->fresh())->with('lessonTopic')->get();

        $this->assertCount(1, $forA);
        $this->assertSame('Parking', $forA->first()->lessonTopic->name);

        $this->assertCount(1, $forB);
        $this->assertSame('Highway Driving', $forB->first()->lessonTopic->name);
    }

    public function test_the_new_instructor_sees_the_lesson_and_rating_on_the_attendance_screen(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 08:30:00'));

        $this->actingAs($this->teacherAUser->fresh())
            ->post(route('instructor.attendance.transfer'), [
                'student_id' => $this->ahmed->id,
                'to_instructor_id' => $this->teacherB->id,
            ]);
        $this->checkIn($this->teacherBUser, '2026-09-09', 'highway_driving', 'good');

        $this->actingAs($this->teacherBUser->fresh())
            ->get(route('instructor.attendance.index'))
            ->assertOk()
            ->assertSee('Highway Driving')
            ->assertSee('Good');
    }

    public function test_a_student_sees_the_lesson_attached_to_each_of_their_days(): void
    {
        $this->checkIn($this->teacherAUser, '2026-09-08', 'parking', 'excellent');

        $studentUser = $this->makeUser(Role::STUDENT);
        $this->ahmed->forceFill(['user_id' => $studentUser->id])->save();

        $this->actingAs($studentUser)
            ->get(route('student.lessons'))
            ->assertOk()
            ->assertSee('Parking')
            ->assertSee('Excellent');
    }
}
