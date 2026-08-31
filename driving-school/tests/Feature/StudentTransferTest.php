<?php

namespace Tests\Feature;

use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentInstructorAssignment;
use App\Models\User;
use App\Services\StudentTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudentTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $xasanUser;

    private User $nasteexoUser;

    private Instructor $xasan;

    private Instructor $nasteexo;

    private Student $maryan;

    private Student $ahmed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $this->xasanUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Xasan Maxamuud']);
        $this->nasteexoUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Nasteexo Aadan']);
        $this->xasan = $this->makeInstructor('Xasan Maxamuud', $this->xasanUser);
        $this->nasteexo = $this->makeInstructor('Nasteexo Aadan', $this->nasteexoUser);

        $this->maryan = $this->makeStudent('Maryan Cabdi', $this->xasan);
        $this->ahmed = $this->makeStudent('Ahmed', $this->nasteexo);

        StudentInstructorAssignment::create([
            'student_id' => $this->maryan->id,
            'instructor_id' => $this->xasan->id,
            'assigned_from' => Carbon::today()->subDays(20),
            'is_current' => true,
            'reason' => 'Initial assignment',
        ]);
    }

    public function test_an_instructor_can_transfer_his_own_student(): void
    {
        $this->actingAs($this->xasanUser)
            ->post(route('instructor.transfers.store'), [
                'student_id' => $this->maryan->id,
                'to_instructor_id' => $this->nasteexo->id,
                'transfer_date' => Carbon::today()->toDateString(),
                'reason' => 'Instructor unavailable',
            ])
            ->assertRedirect(route('instructor.transfers.index'));

        $this->assertSame($this->nasteexo->id, $this->maryan->fresh()->current_instructor_id);
    }

    public function test_an_instructor_cannot_transfer_another_instructors_student(): void
    {
        $this->actingAs($this->xasanUser)
            ->post(route('instructor.transfers.store'), [
                'student_id' => $this->ahmed->id,
                'to_instructor_id' => $this->xasan->id,
                'transfer_date' => Carbon::today()->toDateString(),
                'reason' => 'Taking over',
            ])
            ->assertForbidden();

        $this->assertSame($this->nasteexo->id, $this->ahmed->fresh()->current_instructor_id);
        $this->assertDatabaseCount('student_transfers', 0);
    }

    public function test_after_a_transfer_the_student_moves_between_the_two_lists(): void
    {
        app(StudentTransferService::class)->transfer(
            $this->maryan, $this->nasteexo, 'Instructor unavailable', $this->xasanUser,
        );

        $this->actingAs($this->xasanUser)
            ->get(route('instructor.students.index'))
            ->assertDontSee('Maryan Cabdi');

        $this->actingAs($this->nasteexoUser)
            ->get(route('instructor.students.index'))
            ->assertSee('Maryan Cabdi');
    }

    public function test_the_previous_assignment_is_kept_as_history_not_deleted(): void
    {
        $transferDate = Carbon::today();

        app(StudentTransferService::class)->transfer(
            $this->maryan, $this->nasteexo, 'Instructor unavailable', $this->xasanUser, $transferDate,
        );

        $assignments = $this->maryan->assignments()->orderBy('assigned_from')->get();

        $this->assertCount(2, $assignments, 'The historical assignment must be preserved.');

        $this->assertSame($this->xasan->id, $assignments[0]->instructor_id);
        $this->assertFalse($assignments[0]->is_current);
        $this->assertSame(
            $transferDate->copy()->subDay()->toDateString(),
            $assignments[0]->assigned_to->toDateString(),
        );

        $this->assertSame($this->nasteexo->id, $assignments[1]->instructor_id);
        $this->assertTrue($assignments[1]->is_current);
        $this->assertNull($assignments[1]->assigned_to);
    }

    public function test_a_transfer_record_is_written_with_both_instructors(): void
    {
        app(StudentTransferService::class)->transfer(
            $this->maryan, $this->nasteexo, 'Instructor unavailable', $this->xasanUser,
        );

        $this->assertDatabaseHas('student_transfers', [
            'student_id' => $this->maryan->id,
            'from_instructor_id' => $this->xasan->id,
            'to_instructor_id' => $this->nasteexo->id,
            'reason' => 'Instructor unavailable',
        ]);
    }

    public function test_the_receiving_instructor_can_then_transfer_the_student_back(): void
    {
        app(StudentTransferService::class)->transfer(
            $this->maryan, $this->nasteexo, 'Instructor unavailable', $this->xasanUser,
        );

        // Xasan no longer owns her.
        $this->actingAs($this->xasanUser)
            ->post(route('instructor.transfers.store'), [
                'student_id' => $this->maryan->id,
                'to_instructor_id' => $this->xasan->id,
                'transfer_date' => Carbon::today()->toDateString(),
                'reason' => 'Taking back',
            ])
            ->assertForbidden();

        // Nasteexo does.
        $this->actingAs($this->nasteexoUser)
            ->post(route('instructor.transfers.store'), [
                'student_id' => $this->maryan->id,
                'to_instructor_id' => $this->xasan->id,
                'transfer_date' => Carbon::today()->toDateString(),
                'reason' => 'Returning student',
            ])
            ->assertRedirect();

        $this->assertSame($this->xasan->id, $this->maryan->fresh()->current_instructor_id);
        $this->assertCount(3, $this->maryan->assignments);
    }

    public function test_an_admin_can_transfer_any_student(): void
    {
        $admin = $this->makeUser(Role::ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.transfers.store'), [
                'student_id' => $this->ahmed->id,
                'to_instructor_id' => $this->xasan->id,
                'transfer_date' => Carbon::today()->toDateString(),
                'reason' => 'Rebalancing workload',
            ])
            ->assertRedirect(route('admin.transfers.index'));

        $this->assertSame($this->xasan->id, $this->ahmed->fresh()->current_instructor_id);
    }
}
