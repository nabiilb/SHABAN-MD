<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Puts the register's two training columns back where they belong on students
 * who are already imported.
 *
 * The register keeps the length of the course and the days still to run in two
 * different columns — "Mudadda" (Bil, 15Maalin) and the one Excel named
 * "Column1" (a completion word, or a number). Reading the first as the second,
 * or the second as the first, leaves a student showing a full course still
 * ahead of them when most of it is behind.
 *
 * The workbook is read through AlphaSchoolImporter, so there is exactly one
 * interpretation of it in the application and the repair cannot drift from the
 * import. Matching is the importer's too: the normalised phone number.
 *
 * It writes four fields and nothing else. It creates no student, no
 * attendance, no payment; it touches no instructor, session, queue or
 * financial record. A student the register says nothing new about is left
 * alone, so running it twice changes nothing the second time.
 */
class AlphaTrainingProgressRepair
{
    public const UPDATE_REQUIRED = 'UPDATE_REQUIRED';

    public const UPDATE_REMAINING = 'UPDATE_REMAINING';

    public const UPDATE_BOTH = 'UPDATE_REQUIRED_AND_REMAINING';

    public const MARK_COMPLETED = 'MARK_COMPLETED';

    public const NO_CHANGE = 'NO_CHANGE';

    public const NEEDS_REVIEW = 'NEEDS_REVIEW';

    public const NOT_MATCHED = 'NOT_MATCHED';

    /** The only columns this repair is allowed to write. */
    public const WRITES = [
        'required_training_days',
        'opening_remaining_days',
        'opening_remaining_from',
        'status',
    ];

    public function __construct(private readonly AlphaSchoolImporter $importer) {}

    /**
     * What the repair would do to every student the register names.
     *
     * Reads the workbook and the students; writes nothing.
     *
     * @return array<string, mixed>
     */
    public function plan(string $path, ?string $sheet = null): array
    {
        $parsed = $this->importer->parse($path, $sheet ?: config('alpha_school_import.sheet', 'Sheet1'));

        $rows = [];

        foreach ($parsed['records'] as $record) {
            if ($record['action'] === 'skip') {
                continue;
            }

            $rows[] = $this->row($record);
        }

        return [
            'source' => $path,
            'rows_read' => $parsed['rows_read'],
            'blank' => $parsed['blank'],
            'rows' => $rows,
            'notices' => $parsed['notices'],
        ];
    }

    /**
     * Writes the plan.
     *
     * One transaction, four columns, and only the rows the register actually
     * changes. Everything else about the student is left exactly as it is.
     *
     * @param  array<string, mixed>  $plan
     * @return array{updated:int, unchanged:int, skipped:int, failures:array<int, array<string, string>>, fields:array<string, int>}
     */
    public function apply(array $plan): array
    {
        return DB::transaction(function () use ($plan) {
            $result = ['updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failures' => [], 'fields' => []];

            foreach ($plan['rows'] as $row) {
                if ($row['changes'] === []) {
                    $row['decision'] === self::NO_CHANGE ? $result['unchanged']++ : $result['skipped']++;

                    continue;
                }

                try {
                    $student = Student::withTrashed()->find($row['student_id']);

                    if (! $student) {
                        $result['skipped']++;

                        continue;
                    }

                    // forceFill over a whitelist: the register cannot reach a
                    // column this repair was not asked to correct, whatever
                    // else a record happens to carry.
                    $student->forceFill(array_intersect_key(
                        $row['changes'],
                        array_flip(self::WRITES),
                    ))->save();

                    foreach (array_keys($row['changes']) as $field) {
                        $result['fields'][$field] = ($result['fields'][$field] ?? 0) + 1;
                    }

                    $result['updated']++;
                } catch (Throwable $e) {
                    $result['failures'][] = [
                        'row' => (string) $row['row'],
                        'name' => $row['name'],
                        'reason' => $e->getMessage(),
                    ];
                }
            }

            return $result;
        });
    }

    /* ------------------------------------------------------------------
       One register row against one student
       ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function row(array $record): array
    {
        $source = $record['source'] ?? [];

        $row = [
            'row' => $record['row'],
            'name' => $record['student']['full_name'],
            'phone' => $record['student']['phone'],
            'duration_raw' => $source['duration_raw'] ?? null,
            'remaining_raw' => $source['column_one_raw'] ?? null,
            'student_id' => $record['existing_id'],
            'before' => null,
            'after' => null,
            'changes' => [],
            'decision' => self::NOT_MATCHED,
            'reason' => __('No student on file has this phone number'),
        ];

        if (! $record['existing_id']) {
            return $row;
        }

        $student = Student::withTrashed()->find($record['existing_id']);

        if (! $student) {
            return $row;
        }

        $row['before'] = $this->snapshot($student);
        $row['reason'] = null;

        [$changes, $decision, $reason] = $this->decide($student, $source);

        $row['changes'] = $changes;
        $row['decision'] = $decision;
        $row['reason'] = $reason;
        $row['after'] = $this->snapshot($this->projected($student, $changes));

        return $row;
    }

    /**
     * What the register says should change about this student, if anything.
     *
     * @param  array<string, mixed>  $source
     * @return array{0: array<string, mixed>, 1: string, 2: string|null}
     */
    protected function decide(Student $student, array $source): array
    {
        $changes = [];

        $required = $source['duration_days'] ?? null;

        if ($required !== null && (int) $student->required_training_days !== (int) $required) {
            $changes['required_training_days'] = (int) $required;
        }

        // The register finished with this student. Nothing else about the row
        // matters: the model reads a completed student as nought remaining and
        // the whole course behind them.
        if (($source['column_one_status'] ?? null) === Student::COMPLETED) {
            if ($student->status !== Student::COMPLETED) {
                $changes['status'] = Student::COMPLETED;

                return [$changes, self::MARK_COMPLETED, null];
            }

            return [$changes, $changes === [] ? self::NO_CHANGE : self::UPDATE_REQUIRED, null];
        }

        $remaining = $source['column_one_remaining'] ?? null;

        // A column that was written in but not understood — "5/" — is reported
        // and left alone. Reading it as five would be a guess at somebody's
        // course, and the wrong guess is invisible once it is saved.
        if ($remaining === null && ($source['column_one_raw'] ?? null) !== null && ($source['column_one_status'] ?? null) === null) {
            return [[], self::NEEDS_REVIEW, __('The remaining column reads ":value", which is neither a completion word nor a whole number', [
                'value' => $source['column_one_raw'],
            ])];
        }

        // Blank: the student keeps counting down from attendance as before.
        // The course length is never copied across as a balance.
        if ($remaining === null) {
            return [$changes, $changes === [] ? self::NO_CHANGE : self::UPDATE_REQUIRED, null];
        }

        $against = $changes['required_training_days'] ?? (int) $student->required_training_days;

        if ($remaining > $against) {
            return [[], self::NEEDS_REVIEW, __('The register leaves :remaining days of a :required day course — more days than the course has', [
                'remaining' => $remaining,
                'required' => $against,
            ])];
        }

        if ((int) $student->opening_remaining_days !== $remaining || $student->opening_remaining_days === null) {
            $changes['opening_remaining_days'] = $remaining;
        }

        // The baseline is only set when the balance itself is new. Re-running
        // the repair on another day must not quietly move the line that says
        // which attendance counts against it.
        if (isset($changes['opening_remaining_days']) || $student->opening_remaining_from === null) {
            $changes['opening_remaining_from'] = $this->baseline();
        }

        if ($changes === []) {
            return [$changes, self::NO_CHANGE, null];
        }

        return [
            $changes,
            isset($changes['required_training_days']) ? self::UPDATE_BOTH : self::UPDATE_REMAINING,
            null,
        ];
    }

    /**
     * The student as they would be, without saving anything.
     *
     * A shallow clone keeps the key, so the attendance behind the accessors is
     * still the student's own; the attributes are copied by value, so the row
     * on file is untouched.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function projected(Student $student, array $changes): Student
    {
        $projected = clone $student;

        foreach ($changes as $field => $value) {
            $projected->setAttribute($field, $value);
        }

        return $projected;
    }

    /**
     * The four figures a person actually reads off the screen.
     *
     * Taken from the model's own accessors rather than worked out again here,
     * so the report cannot promise a number the application would not show.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(Student $student): array
    {
        return [
            'required' => (int) $student->required_training_days,
            'opening' => $student->opening_remaining_days === null ? null : (int) $student->opening_remaining_days,
            'opening_from' => $student->opening_remaining_from?->toDateString(),
            'status' => $student->status,
            'remaining' => $student->remaining_days,
            'completed' => $student->effective_completed_days,
            'progress' => $student->progress_percentage,
        ];
    }

    /** The date an opening balance read from the register is true as of. */
    protected function baseline(): string
    {
        return config('alpha_school_import.opening_remaining_from') ?: today()->toDateString();
    }
}
