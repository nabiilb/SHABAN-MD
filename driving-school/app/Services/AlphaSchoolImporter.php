<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\User;
use App\Support\DocumentNumber;
use App\Support\PhoneNumber;
use App\Support\XlsxReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads the school's own register — ALPHA SCHOOL.xlsx — into students and
 * their payments.
 *
 * The sheet is a hand-kept register, so almost nothing about it is uniform:
 * dates are sometimes real Excel dates and sometimes text, amounts are
 * sometimes words, "NONE" means zero, and more than half the rows are blank
 * padding. The parse is therefore separate from the write: parse() decides
 * what would happen and why, apply() carries it out, and the command can run
 * the first without the second.
 *
 * Nothing here invents data. A row the sheet cannot answer for — no name, no
 * phone — is reported rather than filled in with a guess.
 */
class AlphaSchoolImporter
{
    /** Stamped on every payment this importer writes, so a rerun knows its own. */
    public const SOURCE = 'ALPHA-IMPORT';

    /** Rows that total a section rather than describe a student. */
    private const SUMMARY_NAMES = ['wadarta', 'wadarta guud', 'total'];

    /**
     * Excel column => the heading it carries, and what that heading means.
     *
     * The two that matter to a student's progress sit next to each other and
     * say different things. H is how long the course is. I is how much of it
     * is left, or that it is finished. Nothing else on the row is training
     * days at all — G is money the student still owes, and has no business
     * anywhere near this calculation.
     */
    public const COLUMNS = [
        'A' => 'TAARIIKHDA — start date',
        'B' => 'T/T/ — register number',
        'C' => 'MAGACA SEDDEXEN — full name',
        'D' => 'LAMBARKA — phone',
        'E' => 'DEGMADA — address',
        'F' => 'LACAGTA BAXSHEY — money paid',
        'G' => 'LACAGTA HARAA — money still owed (NOT training days)',
        'H' => 'Mudadda — training duration, the required training days',
        'I' => 'Column1 — remaining training days, or a completion word',
    ];

    /**
     * Works out what importing this file would do, touching nothing.
     *
     * @return array{rows_read:int, blank:int, records:array, notices:array}
     */
    public function parse(string $path, string $sheet = 'Sheet1'): array
    {
        $rows = XlsxReader::rows($path, $sheet);
        unset($rows[1]); // The header.

        $records = [];
        $notices = [
            'dates_read_day_first' => [],
            'dates_carried_forward' => [],
            'amounts_not_numeric' => [],
            'durations_not_understood' => [],
            'remaining_not_understood' => [],
            'duplicates_in_file' => [],
            'years_out_of_step' => [],
            'dates_corrected_by_config' => [],
            'rows_skipped_by_config' => [],
        ];

        $blank = 0;
        $lastDate = null;
        $seenPhones = [];

        foreach ($rows as $number => $cells) {
            $name = $this->cleanName($cells['C'] ?? null);

            if ($name === null) {
                $blank++;

                continue;
            }

            if (in_array(mb_strtolower($name), self::SUMMARY_NAMES, true)) {
                $records[] = $this->rejected($number, $name, null, __('Section total, not a student'));

                continue;
            }

            if (in_array($number, $this->setting('skip_rows', []), true)) {
                $notices['rows_skipped_by_config'][] = ['row' => $number, 'name' => $name];
                $records[] = $this->rejected($number, $name, null, __('Excluded by config/alpha_school_import.php'));

                continue;
            }

            $phone = $this->cleanPhone($cells['D'] ?? null);

            if ($phone === null) {
                $records[] = $this->rejected($number, $name, null, __('No phone number — the register needs one to identify a student'));

                continue;
            }

            $corrections = $this->setting('date_corrections', []);

            if (isset($corrections[$number])) {
                [$asRead] = $this->cleanDate($cells['A'] ?? null, $lastDate);
                $date = $corrections[$number];
                $how = 'corrected';

                $notices['dates_corrected_by_config'][] = [
                    'row' => $number, 'name' => $name, 'was' => (string) $asRead, 'now' => $date,
                ];
            } else {
                [$date, $how] = $this->cleanDate($cells['A'] ?? null, $lastDate);
            }

            if ($date === null) {
                $records[] = $this->rejected($number, $name, $phone, __('No usable date, and no dated row above it'));

                continue;
            }

            if ($how === 'day_first') {
                $notices['dates_read_day_first'][] = ['row' => $number, 'name' => $name, 'date' => $date];
            }

            if ($how === 'carried') {
                $notices['dates_carried_forward'][] = ['row' => $number, 'name' => $name, 'date' => $date];
            }

            $lastDate = $date;

            if (isset($seenPhones[$phone])) {
                $notices['duplicates_in_file'][] = [
                    'row' => $number, 'name' => $name, 'phone' => $phone, 'first_seen' => $seenPhones[$phone],
                ];

                $records[] = $this->rejected($number, $name, $phone, __('Same phone as row :row', ['row' => $seenPhones[$phone]]));

                continue;
            }

            $seenPhones[$phone] = $number;

            [$paid, $paidNote] = $this->cleanAmount($cells['F'] ?? null);

            if ($paidNote !== null) {
                $notices['amounts_not_numeric'][] = ['row' => $number, 'name' => $name, 'value' => $paidNote];
            }

            [$remaining, $remainingNote] = $this->cleanAmount($cells['G'] ?? null);

            if ($remainingNote !== null) {
                $notices['amounts_not_numeric'][] = ['row' => $number, 'name' => $name, 'value' => $remainingNote];
            }

            [$days, $durationText] = $this->cleanDuration($cells['H'] ?? null);

            if ($durationText !== null && $days === null) {
                $notices['durations_not_understood'][] = ['row' => $number, 'name' => $name, 'value' => $durationText];
            }

            // Column I says one of two things: that the student finished, or
            // how many training days they have left. It is read on its own,
            // never from column H — the length of a course is not a balance.
            [$status, $openingRemaining, $remainingText] = $this->cleanRemaining($cells['I'] ?? null);

            if ($remainingText !== null && $status === null && $openingRemaining === null) {
                $notices['remaining_not_understood'][] = ['row' => $number, 'name' => $name, 'value' => $remainingText];
            }

            $existing = Student::withTrashed()->where('phone', $phone)->first();

            $records[] = [
                'row' => $number,
                'action' => $existing ? 'update' : 'create',
                'reason' => null,
                'existing_id' => $existing?->id,
                // The two register columns as the parser understood them,
                // kept apart from the student they were folded into. A
                // student's status is 'active' both when the register says so
                // and when it says nothing, and only this can tell the two
                // apart — which is what stops a repair reading a silent column
                // as an instruction to un-complete somebody.
                'source' => [
                    'duration_raw' => $durationText,
                    'duration_days' => $days,
                    'remaining_raw' => $remainingText,
                    'remaining_status' => $status,
                    'remaining_days' => $openingRemaining,
                ],
                'student' => [
                    'full_name' => $name,
                    'phone' => $phone,
                    'address' => $this->cleanText($cells['E'] ?? null),
                    'start_date' => $date,
                    'required_training_days' => $days ?? 24,
                    'status' => $status ?? 'active',
                    // The register's own count of days still to run, and the
                    // date it was true. Null for a completed student and for a
                    // row that gave no number — those keep the ordinary
                    // attendance-based calculation.
                    'opening_remaining_days' => $openingRemaining,
                    'opening_remaining_from' => $openingRemaining === null ? null : $this->openingDate(),
                    'total_fee' => round($paid + $remaining, 2),
                    'notes' => $this->notes($number, $durationText, $paidNote, $remainingText),
                    'email' => null,
                    'date_of_birth' => null,
                    'current_instructor_id' => null,
                ],
                'payment' => $paid > 0 ? [
                    'amount' => round($paid, 2),
                    'payment_date' => $date,
                    'payment_method' => $this->setting('payment_method', 'cash'),
                    'reference' => self::SOURCE.':'.$number,
                ] : null,
            ];
        }

        $notices['years_out_of_step'] = $this->yearsOutOfStep($records);

        return [
            'rows_read' => count($rows),
            'blank' => $blank,
            'records' => $records,
            'notices' => $notices,
        ];
    }

    /**
     * Carries out a plan. Each row is its own transaction: one unusable row
     * cannot cost the other hundred, and a rerun picks up where it stopped.
     *
     * @return array{created:int, updated:int, skipped:int, payments:int, payments_existing:int, failures:array}
     */
    public function apply(array $plan, ?User $actor = null): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'payments' => 0, 'payments_existing' => 0, 'failures' => []];

        foreach ($plan['records'] as $record) {
            if ($record['action'] === 'skip') {
                $result['skipped']++;

                continue;
            }

            try {
                DB::transaction(function () use ($record, $actor, &$result) {
                    $student = $record['existing_id']
                        ? $this->fillBlanks(Student::withTrashed()->findOrFail($record['existing_id']), $record['student'])
                        : $this->createStudent($record['student'], $actor);

                    $result[$record['existing_id'] ? 'updated' : 'created']++;

                    if ($record['payment']) {
                        $this->recordPayment($student, $record['payment'], $actor, $result);
                    }
                });
            } catch (\Throwable $e) {
                $result['failures'][] = [
                    'row' => $record['row'],
                    'name' => $record['student']['full_name'] ?? '',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    protected function createStudent(array $data, ?User $actor): Student
    {
        $student = Student::create([
            ...$data,
            'student_number' => DocumentNumber::next(Student::class, 'student_number', 'STD'),
        ]);

        AuditLogger::created($student, "Student {$student->full_name} imported from the Alpha School register");

        return $student;
    }

    /**
     * A student who is already on the system keeps what the office has entered
     * since. Only fields still empty are filled, so a rerun adds information
     * and never takes any away.
     */
    protected function fillBlanks(Student $student, array $data): Student
    {
        $fillable = array_filter([
            'address' => $student->address ?: $data['address'],
            'notes' => $student->notes ?: $data['notes'],
        ], fn ($value) => filled($value));

        if ($fillable !== [] && $student->fill($fillable)->isDirty()) {
            $student->save();
        }

        return $student;
    }

    /**
     * The reference is the row it came from, so importing the same file twice
     * finds the payment already there instead of banking it again.
     */
    protected function recordPayment(Student $student, array $payment, ?User $actor, array &$result): void
    {
        $existing = StudentPayment::withTrashed()
            ->where('student_id', $student->id)
            ->where('reference', $payment['reference'])
            ->exists();

        if ($existing) {
            $result['payments_existing']++;

            return;
        }

        $record = StudentPayment::create([
            ...$payment,
            'payment_number' => DocumentNumber::next(StudentPayment::class, 'payment_number', 'PAY'),
            'student_id' => $student->id,
            'notes' => __('Imported from the Alpha School register'),
            'created_by' => $actor?->id,
        ]);

        $result['payments']++;

        AuditLogger::created($record, "Payment {$record->payment_number} of {$record->amount} imported for {$student->full_name}");
    }

    /**
     * Dates whose year is not the register's year.
     *
     * The sheet is one year's intake, so a 2027 or 2029 sitting between rows of
     * 2026 is a slip of the pen. The importer does not correct it — the date it
     * was given is the only date it has — but it says so, because an intake
     * dated three years out will quietly break every report that groups by
     * month.
     */
    protected function yearsOutOfStep(array $records): array
    {
        $years = [];

        foreach ($records as $record) {
            if ($record['action'] !== 'skip') {
                $years[] = substr($record['student']['start_date'], 0, 4);
            }
        }

        if ($years === []) {
            return [];
        }

        $counts = array_count_values($years);
        arsort($counts);
        $usual = array_key_first($counts);

        $odd = [];

        foreach ($records as $record) {
            if ($record['action'] === 'skip') {
                continue;
            }

            if (! str_starts_with($record['student']['start_date'], $usual)) {
                $odd[] = [
                    'row' => $record['row'],
                    'name' => $record['student']['full_name'],
                    'date' => $record['student']['start_date'],
                    'register_year' => $usual,
                ];
            }
        }

        return $odd;
    }

    /**
     * A setting from config/alpha_school_import.php.
     *
     * Read through here rather than inline so every judgement the importer
     * makes about this spreadsheet is in one readable file.
     */
    protected function setting(string $key, mixed $default = null): mixed
    {
        return config("alpha_school_import.{$key}", $default);
    }

    /* ----------------------------------------------------------------
     | Cleaning
     | ---------------------------------------------------------------- */

    protected function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $text === '' ? null : $text;
    }

    protected function cleanName(mixed $value): ?string
    {
        $name = $this->cleanText($value);

        return $name !== null && preg_match('/\p{L}/u', $name) ? $name : null;
    }

    /**
     * Somali mobile numbers are nine digits; the register writes them bare.
     * Everything is stored in the same +252 form the rest of the app uses, so
     * a phone typed into the admin screen and one imported here match — which
     * is why this is PhoneNumber's job and not a second copy of the rule.
     */
    protected function cleanPhone(mixed $value): ?string
    {
        return PhoneNumber::normalize($value);
    }

    /**
     * The register writes dates day-first — 14/7/2026, 04.08.2026 — but Excel
     * stored a handful of them as real dates after reading them month-first,
     * so 6/7 became 6 June rather than 6 July. Where a stored date could be
     * either, it is read the way the rest of the column is written, and the
     * row is listed in the report so it can be checked.
     *
     * A row with no date at all takes the date of the row above it, which is
     * how the register is kept; that is reported too.
     *
     * @return array{0: ?string, 1: ?string} the date, and how it was arrived at
     */
    protected function cleanDate(mixed $value, ?string $previous): array
    {
        $text = $this->cleanText($value);

        if ($text === null) {
            return [$previous, $previous === null ? null : 'carried'];
        }

        // Already a date, because the cell was formatted as one.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m)) {
            [, $year, $month, $day] = $m;

            if ((int) $day <= 12 && $this->setting('read_stored_dates_day_first', true)) {
                return [sprintf('%s-%02d-%02d', $year, (int) $day, (int) $month), 'day_first'];
            }

            return [$text, null];
        }

        if (preg_match('/^(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{2,4})$/', $text, $m)) {
            [, $day, $month, $year] = $m;
            $year = strlen($year) === 2 ? '20'.$year : $year;

            if (checkdate((int) $month, (int) $day, (int) $year)) {
                return [sprintf('%s-%02d-%02d', $year, (int) $month, (int) $day), null];
            }
        }

        try {
            return [Carbon::parse($text)->toDateString(), null];
        } catch (\Throwable) {
            return [$previous, $previous === null ? null : 'carried'];
        }
    }

    /**
     * "NONE" in any spelling means nothing outstanding. A figure with a word
     * attached — "70 primenamk" — keeps its figure, and the words are carried
     * into the student's notes rather than thrown away.
     *
     * @return array{0: float, 1: ?string} the amount, and the text if it was not a plain number
     */
    protected function cleanAmount(mixed $value): array
    {
        if ($value === null) {
            return [0.0, null];
        }

        if (is_numeric($value)) {
            return [(float) $value, null];
        }

        $text = $this->cleanText($value);

        if ($text === null || preg_match('/^none$/i', $text)) {
            return [0.0, null];
        }

        if (preg_match('/(\d+(?:\.\d+)?)/', $text, $m)) {
            return [(float) $m[1], $text];
        }

        return [0.0, $text];
    }

    /**
     * "15 Maalin" — however it is spelled — is fifteen days; "Bil" is a
     * month, "2 Bil" two of them.
     * Anything else keeps its words and leaves the school's default standing.
     *
     * @return array{0: ?int, 1: ?string}
     */
    protected function cleanDuration(mixed $value): array
    {
        $text = $this->cleanText($value);

        if ($text === null) {
            return [null, null];
        }

        // The register spells it Maalin, maalin and malin.
        if (preg_match('/^(\d+)\s*m+a+lin/i', $text, $m)) {
            return [(int) $m[1], $text];
        }

        if (preg_match('/^(\d+)?\s*bil/i', $text, $m)) {
            return [max(1, (int) ($m[1] ?? 1)) * 30, $text];
        }

        if (is_numeric($text)) {
            return [(int) $text, $text];
        }

        return [null, $text];
    }

    /**
     * The register writes "complate" for a student who has finished.
     *
     * @return array{0: ?string, 1: ?string}
     */
    /**
     * The register's Column1, which carries two different kinds of answer.
     *
     * A word — the school writes "complate", and has spelled it four ways —
     * means the student finished, and their remaining days are none.
     *
     * A number means that many training days are still to run. It is the
     * school's own count, made against a history this application never saw,
     * so it is kept as an opening balance rather than turned into attendance.
     *
     * Anything else — "5/" is in the file once — is neither, and is returned as
     * text alone so the caller reports the row instead of guessing which was
     * meant. A guess here would either finish a student who has not, or invent
     * a number of days out of a typo.
     *
     * @return array{0: ?string, 1: ?int, 2: ?string} status, opening days, raw text
     */
    protected function cleanRemaining(mixed $value): array
    {
        $text = $this->cleanText($value);

        if ($text === null) {
            return [null, null, null];
        }

        $letters = preg_replace('/[^a-z]/', '', mb_strtolower($text));

        $status = match ($letters) {
            'complate', 'complete', 'completed', 'compleated', 'dhameystiray' => 'completed',
            'active', 'firfircoon' => 'active',
            'cancelled', 'canceled' => 'cancelled',
            'suspended' => 'suspended',
            default => null,
        };

        // A finished student has nothing left to run, and saying so in the
        // balance as well as the status keeps the two from disagreeing.
        if ($status !== null) {
            return [$status, $status === Student::COMPLETED ? 0 : null, $text];
        }

        // A whole number of days, and nothing else in the cell. "5/" has a
        // stray character, so it is not one — and is reported rather than read
        // as five.
        if (preg_match('/^\d+$/', $text) && (int) $text >= 0) {
            return [null, (int) $text, $text];
        }

        return [null, null, $text];
    }

    /**
     * The date an opening balance is true from. The register's figure is
     * current as at the moment it is imported, so training recorded from today
     * onwards is what reduces it.
     */
    protected function openingDate(): string
    {
        return today()->toDateString();
    }

    /** Whatever the row said that the columns could not hold on their own. */
    protected function notes(int $row, ?string $duration, ?string $amount, ?string $status): string
    {
        $parts = [__('Imported from the Alpha School register, row :row', ['row' => $row])];

        if ($duration) {
            $parts[] = __('Duration as written: :value', ['value' => $duration]);
        }

        if ($amount) {
            $parts[] = __('Amount as written: :value', ['value' => $amount]);
        }

        if ($status) {
            $parts[] = __('Status as written: :value', ['value' => $status]);
        }

        return implode('. ', $parts).'.';
    }

    protected function rejected(int $row, ?string $name, ?string $phone, string $reason): array
    {
        return [
            'row' => $row,
            'action' => 'skip',
            'reason' => $reason,
            'existing_id' => null,
            // The same shape an accepted row has, so anything reading a plan
            // does not have to know which kind of record it is holding.
            'source' => [
                'duration_raw' => null, 'duration_days' => null,
                'remaining_raw' => null, 'remaining_status' => null, 'remaining_days' => null,
            ],
            'student' => ['full_name' => $name, 'phone' => $phone],
            'payment' => null,
        ];
    }
}
