<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads a newer copy of the school's register against the students already on
 * file, and says what has changed.
 *
 * Not an import and not a mirror. The register is a book kept by hand that
 * gets retyped, reordered and corrected between versions, so a second copy of
 * it is neither a fresh set of students nor the whole truth about the school:
 * rows move, a name gains a letter, days left are written in, and students who
 * left the book months ago are still the school's. So this updates what the
 * newer copy plainly says, creates the students it has that the school does
 * not, and touches nothing else — a student missing from the new file is a
 * student the file is silent about, never a student to remove.
 *
 * The workbook is read through AlphaSchoolImporter so there is one
 * interpretation of it in the application, and a balance is corrected through
 * StudentProgressService so there is one calculation behind the days left.
 * Nothing here parses a duration or works out a remainder of its own.
 */
class AlphaSchoolSyncService
{
    public const CREATE = 'CREATE';

    public const UPDATE = 'UPDATE';

    public const NO_CHANGE = 'NO_CHANGE';

    public const NEEDS_REVIEW = 'NEEDS_REVIEW';

    /** The columns a newer register is allowed to correct on a student. */
    public const WRITES = ['full_name', 'address', 'start_date', 'required_training_days', 'total_fee'];

    public const PAYMENT_CREATE = 'PAYMENT_CREATE';

    public const PAYMENT_ALREADY_EXISTS = 'PAYMENT_ALREADY_EXISTS';

    public const PAYMENT_NEEDS_REVIEW = 'PAYMENT_CHANGED_NEEDS_REVIEW';

    public const PAYMENT_NONE = 'PAYMENT_NONE';

    public function __construct(
        private readonly AlphaSchoolImporter $importer,
        private readonly StudentProgressService $progress,
    ) {}

    /**
     * What a newer register would do, touching nothing.
     *
     * @return array<string, mixed>
     */
    public function plan(string $path, ?string $sheet = null, bool $allowReopen = false): array
    {
        $sheet = $sheet ?: config('alpha_school_import.sheet', 'Sheet1');
        $parsed = $this->importer->parse($path, $sheet);

        $repeated = $this->repeatedPhones($parsed['records']);
        $rows = [];

        foreach ($parsed['records'] as $record) {
            $rows[] = $this->row($record, $repeated, $allowReopen);
        }

        return [
            'source' => $path,
            'sheet' => $sheet,
            'rows_read' => $parsed['rows_read'],
            'blank' => $parsed['blank'],
            'rows' => $rows,
            'notices' => $parsed['notices'],
        ];
    }

    /**
     * Writes the plan.
     *
     * Creations go through the importer that would have made them anyway, so a
     * student created by a sync is the same student, numbered and audited the
     * same way, as one created by an import. Updates are applied here, field by
     * named field, and the days left through the one service that owns them.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function apply(array $plan, ?User $actor = null): array
    {
        $result = [
            'created' => 0, 'updated' => 0, 'unchanged' => 0, 'reviewed' => 0,
            'payments_created' => 0, 'payments_existing' => 0,
            'fields' => [], 'failures' => [],
        ];

        $creations = array_values(array_filter($plan['rows'], fn ($r) => $r['decision'] === self::CREATE));

        if ($creations !== []) {
            $imported = $this->importer->apply([
                'records' => array_column($creations, 'record'),
                'notices' => [],
            ], $actor);

            $result['created'] = $imported['created'];
            $result['payments_created'] += $imported['payments'];
            $result['payments_existing'] += $imported['payments_existing'];
            $result['failures'] = array_merge($result['failures'], $imported['failures']);
        }

        foreach ($plan['rows'] as $row) {
            if ($row['decision'] === self::NO_CHANGE) {
                $result['unchanged']++;

                continue;
            }

            if ($row['decision'] === self::NEEDS_REVIEW) {
                $result['reviewed']++;

                continue;
            }

            if ($row['decision'] !== self::UPDATE) {
                continue;
            }

            try {
                $this->update($row, $actor, $result);
            } catch (Throwable $e) {
                $result['failures'][] = [
                    'row' => $row['row'],
                    'name' => $row['name'],
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /* ------------------------------------------------------------------
       One register row against one student
       ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, int>  $repeated
     * @return array<string, mixed>
     */
    protected function row(array $record, array $repeated, bool $allowReopen): array
    {
        $row = [
            'row' => $record['row'],
            'name' => $record['student']['full_name'] ?? '',
            'phone' => $record['student']['phone'] ?? null,
            'student_id' => $record['existing_id'],
            'record' => $record,
            'changes' => [],
            'remaining' => null,
            'status' => null,
            'payment' => self::PAYMENT_NONE,
            'payment_detail' => null,
            'flags' => [],
            'before' => null,
            'after' => null,
            'decision' => self::NEEDS_REVIEW,
            'reason' => $record['reason'] ?? null,
        ];

        // Rows the register itself could not be read from: no name, no phone,
        // no date. The importer has already said why.
        if ($record['action'] === 'skip') {
            // The importer has already said why. Its reasons map onto the
            // counters this report keeps, so a row it turned away for having
            // the same number as an earlier one is counted as what it is.
            $reason = mb_strtolower((string) $record['reason']);

            $row['flags'][] = match (true) {
                str_contains($reason, 'same phone') => 'DUPLICATE_SOURCE_PHONE',
                str_contains($reason, 'phone') => 'INVALID_PHONE',
                default => 'UNREADABLE_ROW',
            };

            return $row;
        }

        // The same number twice in one book is a question for the school, not
        // something to answer by processing whichever row came last.
        if (($repeated[$record['student']['phone']] ?? 0) > 1) {
            $row['flags'][] = 'DUPLICATE_SOURCE_PHONE';
            $row['reason'] = __('This phone number appears on more than one row of the workbook');

            return $row;
        }

        $source = $record['source'] ?? [];

        if (($source['duration_raw'] ?? null) !== null && ($source['duration_days'] ?? null) === null) {
            $row['flags'][] = 'MALFORMED_DURATION';
        }

        if (($source['remaining_raw'] ?? null) !== null
            && ($source['remaining_days'] ?? null) === null
            && ($source['remaining_status'] ?? null) === null) {
            $row['flags'][] = 'MALFORMED_REMAINING';
        }

        if (! $record['existing_id']) {
            $row['decision'] = self::CREATE;
            $row['reason'] = null;
            $row['payment'] = $record['payment'] ? self::PAYMENT_CREATE : self::PAYMENT_NONE;

            return $row;
        }

        return $this->existing($row, $record, $source, $allowReopen);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    protected function existing(array $row, array $record, array $source, bool $allowReopen): array
    {
        $student = Student::withTrashed()->find($record['existing_id']);

        if (! $student) {
            $row['reason'] = __('The student this row matched has gone from the database');

            return $row;
        }

        $row['before'] = $this->snapshot($student);
        $row['reason'] = null;
        $row['changes'] = $this->fieldChanges($student, $record, $source);

        [$remaining, $status, $flags, $blocked] = $this->balanceChange($student, $source, $allowReopen);

        $row['remaining'] = $remaining;
        $row['status'] = $status;
        $row['flags'] = array_merge($row['flags'], $flags);

        [$row['payment'], $row['payment_detail']] = $this->paymentDecision($student, $record);

        if ($blocked) {
            // A student the school finished with, whom the new book gives days
            // back. Real, and not something to do behind anybody's back.
            $row['decision'] = self::NEEDS_REVIEW;
            $row['reason'] = __('The register gives :count days back to a completed student', ['count' => $remaining]);

            return $row;
        }

        $changing = $row['changes'] !== []
            || $remaining !== null
            || $status !== null
            || $row['payment'] === self::PAYMENT_CREATE;

        $row['decision'] = $changing ? self::UPDATE : self::NO_CHANGE;
        $row['after'] = $this->projected($student, $row);

        return $row;
    }

    /**
     * The plain columns a newer register may correct.
     *
     * A blank cell is the book being silent, never an instruction to erase
     * what the school already knows, so only a value that is actually there
     * can change anything.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    protected function fieldChanges(Student $student, array $record, array $source): array
    {
        $incoming = $record['student'];
        $changes = [];

        foreach (['full_name', 'address'] as $field) {
            $value = trim((string) ($incoming[$field] ?? ''));

            if ($value !== '' && $value !== trim((string) $student->{$field})) {
                $changes[$field] = $value;
            }
        }

        if (($incoming['start_date'] ?? null)
            && $incoming['start_date'] !== $student->start_date?->toDateString()) {
            $changes['start_date'] = $incoming['start_date'];
        }

        // Only where the duration was actually understood: a cell nobody can
        // read must not quietly reset a course to the house default.
        if (($source['duration_days'] ?? null) !== null
            && (int) $source['duration_days'] !== (int) $student->required_training_days) {
            $changes['required_training_days'] = (int) $source['duration_days'];
        }

        $fee = round((float) ($incoming['total_fee'] ?? 0), 2);

        if ($fee > 0 && $fee !== round((float) $student->total_fee, 2)) {
            $changes['total_fee'] = $fee;
        }

        return $changes;
    }

    /**
     * What the register says about the days left, and whether it may be done.
     *
     * @param  array<string, mixed>  $source
     * @return array{0: int|null, 1: string|null, 2: array<int, string>, 3: bool}
     */
    protected function balanceChange(Student $student, array $source, bool $allowReopen): array
    {
        $flags = [];

        if (($source['remaining_status'] ?? null) === Student::COMPLETED) {
            if ($student->status === Student::COMPLETED) {
                return [null, null, $flags, false];
            }

            return [null, Student::COMPLETED, ['COMPLETED_BY_NEW_FILE'], false];
        }

        $remaining = $source['remaining_days'] ?? null;

        // A blank column leaves the balance exactly as the school has it.
        if ($remaining === null) {
            return [null, null, $flags, false];
        }

        if ($remaining > (int) $student->required_training_days) {
            return [null, null, ['REMAINING_ABOVE_COURSE'], true];
        }

        if ($remaining === $student->remaining_days) {
            return [null, null, $flags, false];
        }

        if ($remaining > 0 && $student->status === Student::COMPLETED) {
            return [$remaining, null, ['REOPEN_REQUIRED'], ! $allowReopen];
        }

        return [$remaining, null, $flags, false];
    }

    /**
     * Whether this row's money is already banked.
     *
     * Row numbers move between versions of the book, so the reference alone
     * cannot answer it. What can: this student already has a payment that came
     * from this register. If it is for the same money it is the same payment
     * under a new row number; if it is for different money, that is a question
     * about the school's takings and not something to settle by banking it
     * twice.
     *
     * @param  array<string, mixed>  $record
     * @return array{0: string, 1: string|null}
     */
    protected function paymentDecision(Student $student, array $record): array
    {
        if (! $record['payment']) {
            return [self::PAYMENT_NONE, null];
        }

        $amount = round((float) $record['payment']['amount'], 2);

        $existing = StudentPayment::withTrashed()
            ->where('student_id', $student->id)
            ->where('reference', 'like', AlphaSchoolImporter::SOURCE.':%')
            ->get();

        if ($existing->isEmpty()) {
            return [self::PAYMENT_CREATE, __('No register payment on file for this student')];
        }

        $banked = round((float) $existing->sum('amount'), 2);

        if ($banked === $amount) {
            return [self::PAYMENT_ALREADY_EXISTS, __(':amount already banked from the register', ['amount' => $banked])];
        }

        return [self::PAYMENT_NEEDS_REVIEW, __('The register now says :now; :banked is already banked from it', [
            'now' => $amount,
            'banked' => $banked,
        ])];
    }

    /* ------------------------------------------------------------------
       Writing
       ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $result
     */
    protected function update(array $row, ?User $actor, array &$result): void
    {
        DB::transaction(function () use ($row, $actor, &$result) {
            $student = Student::withTrashed()->lockForUpdate()->findOrFail($row['student_id']);
            $original = $student->getOriginal();

            if ($row['changes'] !== []) {
                $student->forceFill(array_intersect_key($row['changes'], array_flip(self::WRITES)))->save();
            }

            if ($row['status'] === Student::COMPLETED && $student->status !== Student::COMPLETED) {
                $student->forceFill(['status' => Student::COMPLETED])->save();
            }

            if ($row['changes'] !== [] || $row['status'] !== null) {
                AuditLogger::updated(
                    $student,
                    __(':name updated from the Alpha register, row :row', [
                        'name' => $student->full_name,
                        'row' => $row['row'],
                    ]),
                    $original,
                );
            }

            // The days left, through the one service that owns them. Never a
            // column written here, and never an attendance row invented.
            if ($row['remaining'] !== null) {
                $this->progress->correctRemaining(
                    $student->refresh(),
                    $row['remaining'],
                    $actor ?? $this->anyActor(),
                    source: 'alpha register sync',
                    mayReopenCompleted: in_array('REOPEN_REQUIRED', $row['flags'], true),
                );
            }

            if ($row['payment'] === self::PAYMENT_CREATE) {
                $this->importer->apply([
                    'records' => [array_merge($row['record'], ['action' => 'update', 'existing_id' => $student->id])],
                    'notices' => [],
                ], $actor);

                $result['payments_created']++;
            }

            foreach (array_keys($row['changes']) as $field) {
                $result['fields'][$field] = ($result['fields'][$field] ?? 0) + 1;
            }

            if ($row['remaining'] !== null) {
                $result['fields']['remaining_days'] = ($result['fields']['remaining_days'] ?? 0) + 1;
            }

            if ($row['status'] !== null) {
                $result['fields']['status'] = ($result['fields']['status'] ?? 0) + 1;
            }

            $result['updated']++;
        });
    }

    /* ------------------------------------------------------------------
       Small helpers
       ------------------------------------------------------------------ */

    /**
     * Phone numbers written on more than one row of the same book.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, int>
     */
    protected function repeatedPhones(array $records): array
    {
        $counts = [];

        // Counted across every row that yielded a number, the ones the
        // importer already turned away included. Otherwise the first of a
        // repeated pair is processed and only the second is questioned, which
        // is the same as choosing between them without being asked.
        foreach ($records as $record) {
            $phone = $record['student']['phone'] ?? null;

            if ($phone) {
                $counts[$phone] = ($counts[$phone] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    protected function snapshot(Student $student): array
    {
        return [
            'full_name' => $student->full_name,
            'address' => $student->address,
            'start_date' => $student->start_date?->toDateString(),
            'required' => (int) $student->required_training_days,
            'remaining' => $student->remaining_days,
            'completed' => $student->effective_completed_days,
            'progress' => $student->progress_percentage,
            'status' => $student->status,
            'total_fee' => round((float) $student->total_fee, 2),
        ];
    }

    /**
     * The student as the sync would leave them, without saving anything.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function projected(Student $student, array $row): array
    {
        $projected = clone $student;
        $projected->forceFill($row['changes']);

        if ($row['status'] !== null) {
            $projected->status = $row['status'];
        }

        if ($row['remaining'] !== null) {
            $projected->opening_remaining_days = $row['remaining'];
            $projected->opening_remaining_from = today()->toDateString();

            if (in_array('REOPEN_REQUIRED', $row['flags'], true)) {
                $projected->status = Student::ACTIVE;
            }
        }

        return $this->snapshot($projected);
    }

    /** The admin a correction is attributed to when a command has no user. */
    protected function anyActor(): User
    {
        return User::query()->whereHas('role', fn ($q) => $q->where('name', 'admin'))->orderBy('id')->firstOrFail();
    }
}
