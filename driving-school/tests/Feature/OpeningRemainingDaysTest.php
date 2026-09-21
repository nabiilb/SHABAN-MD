<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\AlphaSchoolImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Students who arrived mid-course, with a history this application never saw.
 *
 * Remaining days are normally the course length less the days attended, which
 * is right for anyone who registered here and wrong for the register import:
 * the school has been teaching somebody for two months and this system's
 * attendance ledger is empty, so the arithmetic says the whole course is still
 * to run.
 *
 * The register's own column I answers it. A word — the school writes "complate"
 * — means finished. A number means that many days are still to run, and it is
 * kept as an opening balance that new training counts down from. Nothing
 * historical is invented: the days before the import are represented by the
 * number the school itself counted them as.
 */
class OpeningRemainingDaysTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Instructor $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-17 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN);
        $this->instructor = $this->makeInstructor('Cabdi Yuusuf');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A student carrying an opening balance, as the import creates them. */
    private function imported(int $required, ?int $openingRemaining, string $status = 'active'): Student
    {
        $student = $this->makeStudent('Imported '.Student::count(), $this->instructor, [
            'required_training_days' => $required,
        ]);

        $student->forceFill([
            'status' => $status,
            'opening_remaining_days' => $openingRemaining,
            'opening_remaining_from' => $openingRemaining === null ? null : today(),
        ])->save();

        return $student->refresh();
    }

    private function attend(Student $student, string $date): void
    {
        Attendance::create([
            'student_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'attendance_date' => $date,
            'status' => 'present',
            'recorded_by' => $this->admin->id,
        ]);
    }

    /** Reads column I the way the importer does. */
    private function remainingColumn(mixed $value): array
    {
        $importer = new class extends AlphaSchoolImporter
        {
            public function read(mixed $value): array
            {
                return $this->cleanRemaining($value);
            }
        };

        return $importer->read($value);
    }

    /* ================================================================
     | 1 + 8 — reading column I
     | ================================================================ */

    /** 1 — "complate", and the four other ways the school spells it. */
    public function test_a_completed_word_in_column_i_finishes_the_student(): void
    {
        foreach (['complate', 'Complate', 'COMPLETE', 'completed', ' compleated ', 'dhameystiray'] as $written) {
            [$status, $opening, $text] = $this->remainingColumn($written);

            $this->assertSame('completed', $status, "Failed on: {$written}");
            $this->assertSame(0, $opening, 'A finished student has no days left to run.');
            $this->assertSame(trim($written), $text);
        }

        $student = $this->imported(required: 30, openingRemaining: 0, status: 'completed');

        $this->assertSame(0, $student->remaining_days);
        $this->assertSame(100.0, $student->progress_percentage);
    }

    /** 2 — a number is that many days still to run. */
    public function test_a_number_in_column_i_is_the_remaining_days(): void
    {
        foreach ([15, 14, 10, 5, 1, 0] as $days) {
            [$status, $opening] = $this->remainingColumn((string) $days);

            $this->assertNull($status, 'A number says nothing about status.');
            $this->assertSame($days, $opening);
        }

        $student = $this->imported(required: 30, openingRemaining: 15);

        $this->assertSame(15, $student->opening_remaining_days);
        $this->assertSame(15, $student->remaining_days);
    }

    /**
     * 8 — and anything else is reported rather than guessed. "5/" is in the
     * register once; reading it as five would invent a number out of a typo.
     */
    public function test_an_unreadable_column_i_is_reported_not_guessed(): void
    {
        foreach (['5/', '1 5', 'wili meydirin', '-3', '4.5'] as $written) {
            [$status, $opening, $text] = $this->remainingColumn($written);

            $this->assertNull($status, "Failed on: {$written}");
            $this->assertNull($opening, "{$written} must not be read as a number of days.");
            $this->assertSame(trim($written), $text, 'The raw text comes back so the row can be reported.');
        }

        // The real workbook, which holds exactly one such row.
        $plan = app(AlphaSchoolImporter::class)->parse(config('alpha_school_import.file'), 'Sheet1');
        $reported = collect($plan['notices']['remaining_not_understood']);

        $this->assertCount(1, $reported);
        $this->assertSame('5/', $reported->first()['value']);
        $this->assertSame(23, $reported->first()['row']);

        // That student is still imported — as active, with no opening balance.
        $row = collect($plan['records'])->firstWhere('row', 23);
        $this->assertSame('active', $row['student']['status']);
        $this->assertNull($row['student']['opening_remaining_days']);
    }

    /** The whole workbook, read as the school wrote it. */
    public function test_the_register_maps_onto_the_two_rules(): void
    {
        $plan = app(AlphaSchoolImporter::class)->parse(config('alpha_school_import.file'), 'Sheet1');
        $valid = collect($plan['records'])->where('action', '!=', 'skip');

        $completed = $valid->filter(fn ($r) => $r['student']['status'] === 'completed');
        $fromANumber = $valid->filter(fn ($r) => ($r['source']['remaining_days'] ?? null) !== null
            && $r['source']['remaining_status'] === null);

        $this->assertSame(136, $valid->count(), 'The valid-row count must not move.');
        $this->assertSame(40, $completed->count(), 'Every "complate" row that survives becomes completed.');

        // This workbook carries no number in column I at all — 98 blank, 42
        // "complate", one "5/" — so not one balance in it was read from a
        // figure. The rule is built and proved above; this records what the
        // file actually holds, and is the line that would move the day the
        // school fills the column in.
        $this->assertSame(0, $fromANumber->count());

        // Column H is never the source of a balance: every required day count
        // read from the register is a course length and nothing else.
        foreach ($valid as $record) {
            if (($record['source']['remaining_status'] ?? null) === null) {
                continue;
            }

            $this->assertSame(0, $record['student']['opening_remaining_days'],
                'A finished student has nought left to run, not their course length.');
        }

        // A completed row is finished, not part way through, and keeps the
        // course length column H gave it.
        $sample = $completed->first()['student'];
        $this->assertSame(0, $sample['opening_remaining_days']);
        $this->assertGreaterThan(0, $sample['required_training_days']);
    }

    /* ================================================================
     | 3 + 4 — progress from the opening balance
     | ================================================================ */

    /** 3 — required 30, remaining 15, half way. */
    public function test_progress_is_the_course_less_what_is_left(): void
    {
        $student = $this->imported(required: 30, openingRemaining: 15);

        $this->assertSame(15, $student->remaining_days);
        $this->assertSame(15, $student->effective_completed_days, 'Fifteen days are behind them.');
        $this->assertSame(50.0, $student->progress_percentage);

        // And not one of those fifteen is an attendance row.
        $this->assertSame(0, $student->completed_days);
        $this->assertSame(0, Attendance::where('student_id', $student->id)->count());
    }

    /** 4 — required 15, remaining 4. */
    public function test_progress_of_eleven_days_out_of_fifteen(): void
    {
        $student = $this->imported(required: 15, openingRemaining: 4);

        $this->assertSame(4, $student->remaining_days);
        $this->assertSame(11, $student->effective_completed_days);
        $this->assertEqualsWithDelta(73.3, $student->progress_percentage, 0.05);
    }

    /** Clamped: an opening balance longer than the course is still 0%. */
    public function test_progress_is_clamped_when_the_opening_balance_exceeds_the_course(): void
    {
        $student = $this->imported(required: 15, openingRemaining: 20);

        $this->assertSame(20, $student->remaining_days);
        $this->assertSame(0, $student->effective_completed_days, 'Never below zero either.');
        $this->assertSame(0.0, $student->progress_percentage, 'Never below zero.');
    }

    /* ================================================================
     | 5 + 6 — new training counts it down
     | ================================================================ */

    /** 5 — one valid training day takes one off. */
    public function test_each_new_training_day_reduces_the_opening_balance(): void
    {
        $student = $this->imported(required: 30, openingRemaining: 15);

        $this->attend($student, today()->toDateString());
        $this->assertSame(14, $student->fresh()->remaining_days);
        $this->assertEqualsWithDelta(53.3, $student->fresh()->progress_percentage, 0.05);

        $this->attend($student, today()->copy()->addDay()->toDateString());
        $this->assertSame(13, $student->fresh()->remaining_days);

        // Two check-ins on one day are one training day.
        $this->attend($student, today()->copy()->addDay()->toDateString());
        $this->assertSame(13, $student->fresh()->remaining_days);
    }

    /**
     * Attendance dated before the opening balance was taken does not count it
     * down: those days are already inside the number the school gave.
     */
    public function test_training_before_the_opening_date_does_not_count_twice(): void
    {
        $student = $this->imported(required: 30, openingRemaining: 15);

        $this->attend($student, today()->copy()->subDays(10)->toDateString());
        $this->attend($student, today()->copy()->subDay()->toDateString());

        $this->assertSame(15, $student->fresh()->remaining_days, 'The opening number already covers those days.');
        $this->assertSame(2, $student->fresh()->completed_days, 'The attendance itself is still on file.');
    }

    /** 6 — and it never goes below zero, however much training follows. */
    public function test_the_opening_balance_never_goes_negative(): void
    {
        $student = $this->imported(required: 30, openingRemaining: 2);

        foreach (range(0, 5) as $offset) {
            $this->attend($student, today()->copy()->addDays($offset)->toDateString());
        }

        $this->assertSame(0, $student->fresh()->remaining_days);
        $this->assertSame(100.0, $student->fresh()->progress_percentage);
        $this->assertSame(6, $student->fresh()->completed_days);
    }

    /* ================================================================
     | 7 — everybody else is untouched
     | ================================================================ */

    public function test_a_normal_student_still_counts_attendance(): void
    {
        $student = $this->makeStudent('Ordinary', $this->instructor, ['required_training_days' => 30]);

        $this->assertNull($student->opening_remaining_days, 'The column is nullable and unused here.');
        $this->assertFalse($student->hasOpeningBalance());
        $this->assertSame(30, $student->remaining_days);
        $this->assertSame(0.0, $student->progress_percentage);

        foreach (range(1, 12) as $offset) {
            $this->attend($student, today()->copy()->subDays($offset)->toDateString());
        }

        $student->refresh();

        $this->assertSame(12, $student->completed_days);
        $this->assertSame(18, $student->remaining_days);
        $this->assertSame(40.0, $student->progress_percentage);
    }

    /** A completed status still outranks an opening balance. */
    public function test_a_completed_status_outranks_the_opening_balance(): void
    {
        $student = $this->imported(required: 30, openingRemaining: 15);

        $this->assertSame(15, $student->remaining_days);

        $student->update(['status' => 'completed']);

        $this->assertSame(0, $student->fresh()->remaining_days);
        $this->assertSame(100.0, $student->fresh()->progress_percentage);
        $this->assertSame(
            15,
            $student->fresh()->opening_remaining_days,
            'The stored balance is kept, so reopening them restores it.',
        );

        // Reopened, the real figure comes back.
        $student->fresh()->update(['status' => 'active']);
        $this->assertSame(15, $student->fresh()->remaining_days);
    }

    /** Every screen reads the same figures for an imported student. */
    public function test_every_screen_agrees_on_an_imported_students_figures(): void
    {
        $student = $this->imported(required: 30, openingRemaining: 15);

        $row = $this->actingAs($this->admin)
            ->get(route('admin.students.index'))
            ->assertOk()
            ->viewData('students')
            ->firstWhere('id', $student->id);

        $detail = $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->viewData('student');

        foreach ([$row, $detail] as $seen) {
            $this->assertSame(15, $seen->remaining_days);
            $this->assertSame(50.0, $seen->progress_percentage);
            $this->assertSame(0, $seen->completed_days, 'No attendance was invented.');
        }

        $this->assertSame(0, Attendance::count());
    }
}
