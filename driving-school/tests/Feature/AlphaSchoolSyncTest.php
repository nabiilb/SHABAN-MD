<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\TrainingQueueEntry;
use App\Models\User;
use App\Services\AlphaSchoolImporter;
use App\Services\AlphaSchoolSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

/**
 * Reading a newer copy of the register against the students already on file.
 *
 * The register is kept by hand and retyped between versions: rows move, a name
 * gains a letter, days left are written in. So a second copy is neither a fresh
 * set of students nor the whole truth about the school — it corrects what it
 * plainly says, adds the students it has that the school does not, and is
 * silent about everybody else. Silence is never an instruction to delete.
 */
class AlphaSchoolSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-28 09:00:00'));
        $this->admin = $this->makeUser(Role::ADMIN);
    }

    /* ------------------------------------------------------------------
       Students already on file
       ------------------------------------------------------------------ */

    public function test_a_row_that_matches_the_database_changes_nothing(): void
    {
        $student = $this->student('Faa,isa Yuusuf Hussein', '616585451', [
            'address' => 'Waaberi', 'start_date' => '1.7.2026', 'required_training_days' => 30,
        ]);

        $row = $this->planRow([['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', 'Waaberi', '0', 'Bil', '']]);

        $this->assertSame(AlphaSchoolSyncService::NO_CHANGE, $row['decision']);
        $this->assertSame([], $row['changes']);
    }

    public function test_a_changed_name_is_corrected(): void
    {
        $this->student('Faisa Yusuf', '616585451');

        $row = $this->planRow([['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', '', '0', 'Bil', '']]);

        $this->assertSame(AlphaSchoolSyncService::UPDATE, $row['decision']);
        $this->assertSame('Faa,isa Yuusuf Hussein', $row['changes']['full_name']);
    }

    public function test_a_changed_address_is_corrected(): void
    {
        $this->student('Faa,isa Yuusuf Hussein', '616585451', ['address' => 'Hodan']);

        $row = $this->planRow([['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', 'Waaberi', '0', 'Bil', '']]);

        $this->assertSame('Waaberi', $row['changes']['address']);
    }

    public function test_a_blank_cell_never_erases_what_the_school_knows(): void
    {
        $this->student('Faa,isa Yuusuf Hussein', '616585451', [
            'address' => 'Hodan', 'start_date' => '2026-07-01', 'required_training_days' => 30,
        ]);

        $row = $this->planRow([['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', '', '0', 'Bil', '']]);

        $this->assertArrayNotHasKey('address', $row['changes']);
        $this->assertSame('Hodan', Student::sole()->address, 'the address the school had is kept');
        $this->assertSame(AlphaSchoolSyncService::NO_CHANGE, $row['decision']);
    }

    public function test_a_changed_start_date_is_corrected(): void
    {
        $this->student('Faa,isa Yuusuf Hussein', '616585451', ['start_date' => '2026-06-01']);

        $row = $this->planRow([['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', '', '0', 'Bil', '']]);

        $this->assertSame('2026-07-01', $row['changes']['start_date']);
    }

    public function test_changed_required_days_are_corrected(): void
    {
        $this->student('Faa,isa Yuusuf Hussein', '616585451', ['required_training_days' => 30]);

        $row = $this->planRow([['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', '', '0', '15Maalin', '']]);

        $this->assertSame(15, $row['changes']['required_training_days']);
    }

    public function test_an_unreadable_duration_never_resets_the_course(): void
    {
        $this->student('Faa,isa Yuusuf Hussein', '616585451', ['required_training_days' => 30]);

        $row = $this->planRow([['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', '', '0', '4 Qamiis', '']]);

        $this->assertArrayNotHasKey('required_training_days', $row['changes']);
        $this->assertContains('MALFORMED_DURATION', $row['flags']);
    }

    /* ------------------------------------------------------------------
       The days left
       ------------------------------------------------------------------ */

    public function test_a_written_balance_is_applied_through_the_canonical_service(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030', ['required_training_days' => 15]);

        $this->sync([['1.7.2026', 'Nasteexo Sadak', '614503030', '', '0', '15Maalin', '10']]);

        $student->refresh();

        $this->assertSame(15, $student->required_training_days);
        $this->assertSame(10, $student->remaining_days);
        $this->assertSame(5, $student->effective_completed_days);
        $this->assertSame(33.3, $student->progress_percentage);
        $this->assertSame(10, $student->opening_remaining_days);
        $this->assertSame(0, Attendance::count(), 'no attendance was invented');
    }

    public function test_a_completion_word_finishes_the_student(): void
    {
        $student = $this->student('Si,ida Abdullahi Khaliif', '616999250', ['required_training_days' => 20]);

        $row = $this->planRow([['1.7.2026', 'Si,ida Abdullahi Khaliif', '616999250', '', '0', '20 Maalin', 'complate']]);

        $this->assertContains('COMPLETED_BY_NEW_FILE', $row['flags']);

        $this->sync([['1.7.2026', 'Si,ida Abdullahi Khaliif', '616999250', '', '0', '20 Maalin', 'complate']]);

        $this->assertSame(Student::COMPLETED, $student->fresh()->status);
        $this->assertSame(0, $student->fresh()->remaining_days);
    }

    public function test_giving_days_back_to_a_completed_student_is_held_for_review(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030', [
            'required_training_days' => 15, 'status' => Student::COMPLETED,
        ]);

        $register = [['1.7.2026', 'Nasteexo Sadak', '614503030', '', '0', '15Maalin', '5']];

        $row = $this->planRow($register);

        $this->assertSame(AlphaSchoolSyncService::NEEDS_REVIEW, $row['decision']);
        $this->assertContains('REOPEN_REQUIRED', $row['flags']);

        $this->sync($register);

        $this->assertSame(Student::COMPLETED, $student->fresh()->status, 'not reopened behind anybody’s back');
        $this->assertNull($student->fresh()->opening_remaining_days);
    }

    public function test_reopening_happens_only_when_it_is_asked_for(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030', [
            'required_training_days' => 15, 'status' => Student::COMPLETED,
        ]);

        $this->sync([['1.7.2026', 'Nasteexo Sadak', '614503030', '', '0', '15Maalin', '5']], allowReopen: true);

        $this->assertSame(Student::ACTIVE, $student->fresh()->status);
        $this->assertSame(5, $student->fresh()->remaining_days);
    }

    public function test_a_blank_balance_leaves_the_days_alone(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030', ['required_training_days' => 15]);
        $student->forceFill(['opening_remaining_days' => 7, 'opening_remaining_from' => '2026-09-01'])->save();

        $this->sync([['1.7.2026', 'Nasteexo Sadak', '614503030', '', '0', '15Maalin', '']]);

        $this->assertSame(7, $student->fresh()->opening_remaining_days);
    }

    /* ------------------------------------------------------------------
       Students the school does not have
       ------------------------------------------------------------------ */

    public function test_a_new_phone_creates_a_student(): void
    {
        $this->sync([['1.7.2026', 'Cabdi Cusub', '619000111', 'Yaaqshid', '80', '15Maalin', '6']]);

        $student = Student::where('phone', '+252619000111')->sole();

        $this->assertSame('Cabdi Cusub', $student->full_name);
        $this->assertSame(15, $student->required_training_days);
        $this->assertSame(6, $student->remaining_days);
        $this->assertStringStartsWith('STD-', $student->student_number);
    }

    public function test_the_same_number_written_differently_is_the_same_student(): void
    {
        $this->student('Faa,isa Yuusuf Hussein', '616585451');

        // Written with the country code this time, and with punctuation.
        $this->sync([['1.7.2026', 'Faa,isa Yuusuf Hussein', '+252 61-658 5451', '', '0', 'Bil', '']]);

        $this->assertSame(1, Student::count(), 'no second student was created');
    }

    public function test_a_row_with_no_usable_phone_is_held_for_review(): void
    {
        $row = $this->planRow([['1.7.2026', 'Qof Aan Lambar Lahayn', '', '', '0', 'Bil', '']]);

        $this->assertSame(AlphaSchoolSyncService::NEEDS_REVIEW, $row['decision']);
        $this->assertContains('INVALID_PHONE', $row['flags']);
        $this->assertSame(0, Student::count());
    }

    public function test_one_number_on_two_rows_is_held_for_review(): void
    {
        $plan = $this->plan([
            ['1.7.2026', 'Anas Axmed Maxamed', '619910610', '', '0', 'Bil', ''],
            ['1.7.2026', 'Anas Axmed M.', '619910610', '', '0', 'Bil', ''],
        ]);

        foreach ($plan['rows'] as $row) {
            $this->assertSame(AlphaSchoolSyncService::NEEDS_REVIEW, $row['decision']);
            $this->assertContains('DUPLICATE_SOURCE_PHONE', $row['flags']);
        }

        $this->assertSame(0, Student::count());
    }

    /* ------------------------------------------------------------------
       The money
       ------------------------------------------------------------------ */

    public function test_a_payment_already_banked_from_the_register_is_not_banked_again(): void
    {
        $student = $this->student('Faa,isa Yuusuf Hussein', '616585451');

        StudentPayment::create([
            'payment_number' => 'PAY-0001', 'student_id' => $student->id, 'amount' => 110,
            'payment_date' => '1.7.2026', 'payment_method' => 'cash',
            // Banked under the row number it had in the OLD workbook.
            'reference' => AlphaSchoolImporter::SOURCE.':2',
        ]);

        // The same money, on a different row of the new one.
        $register = [
            ['1.7.2026', 'Somebody Else', '619000222', '', '0', 'Bil', ''],
            ['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', '', '110', 'Bil', ''],
        ];

        $row = $this->plan($register)['rows'][1];

        $this->assertSame(AlphaSchoolSyncService::PAYMENT_ALREADY_EXISTS, $row['payment']);

        $this->sync($register);

        $this->assertSame(1, StudentPayment::where('student_id', $student->id)->count());
        $this->assertSame('110.00', (string) StudentPayment::where('student_id', $student->id)->sum('amount'));
    }

    public function test_a_payment_the_register_has_changed_is_flagged_not_banked(): void
    {
        $student = $this->student('Faa,isa Yuusuf Hussein', '616585451');

        StudentPayment::create([
            'payment_number' => 'PAY-0001', 'student_id' => $student->id, 'amount' => 110,
            'payment_date' => '1.7.2026', 'payment_method' => 'cash',
            'reference' => AlphaSchoolImporter::SOURCE.':2',
        ]);

        $register = [['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', '', '150', 'Bil', '']];

        $row = $this->planRow($register);

        $this->assertSame(AlphaSchoolSyncService::PAYMENT_NEEDS_REVIEW, $row['payment']);

        $this->sync($register);

        $this->assertSame(1, StudentPayment::where('student_id', $student->id)->count());
        $this->assertSame('110.00', (string) StudentPayment::where('student_id', $student->id)->sum('amount'));
    }

    /* ------------------------------------------------------------------
       What it must never do
       ------------------------------------------------------------------ */

    public function test_a_student_missing_from_the_new_book_is_left_alone(): void
    {
        $absent = $this->student('Qof Aan Buugga Ku Jirin', '615999888', ['status' => 'active']);
        $before = (array) DB::table('students')->where('id', $absent->id)->first();

        $this->sync([['1.7.2026', 'Cabdi Cusub', '619000111', '', '0', 'Bil', '']]);

        $this->assertNotNull(Student::find($absent->id));
        $this->assertEquals($before, (array) DB::table('students')->where('id', $absent->id)->first());
    }

    public function test_it_preserves_training_history(): void
    {
        $student = $this->student('Faa,isa Yuusuf Hussein', '616585451');
        $teacher = $this->makeUser(Role::INSTRUCTOR);
        $instructor = $this->makeInstructor('Xasan Teacher', $teacher);

        TrainingQueueEntry::create([
            'student_id' => $student->id, 'instructor_id' => $instructor->id,
            'queue_date' => '2026-09-28', 'position' => 1, 'status' => 'waiting',
            'planned_minutes' => 30, 'queued_by' => $teacher->id, 'queued_at' => now(),
        ]);

        $tables = ['training_queue_entries', 'training_sessions', 'training_evaluations',
            'attendance', 'company_expenses', 'company_debts', 'fuel_records'];

        $before = [];

        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toArray();
        }

        $this->sync([['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', 'Waaberi', '0', '15Maalin', '3']]);

        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->get()->toArray(), "The sync changed {$table}.");
        }
    }

    public function test_a_dry_run_issues_no_write_at_all(): void
    {
        $this->student('Faa,isa Yuusuf Hussein', '616585451', ['required_training_days' => 30]);

        $path = $this->register([
            ['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', 'Waaberi', '110', '15Maalin', '4'],
            ['1.7.2026', 'Cabdi Cusub', '619000111', '', '80', 'Bil', '6'],
        ]);

        $writes = [];

        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        Artisan::call('alpha-school:sync', ['--file' => $path, '--dry-run' => true, '--details' => 1]);

        $this->assertSame([], $writes, 'A dry run issued a write: '.implode(' | ', $writes));
        $this->assertStringContainsString('DATABASE WRITES: 0', Artisan::output());
        $this->assertSame(1, Student::count());
    }

    public function test_running_the_same_book_twice_changes_nothing_the_second_time(): void
    {
        $this->student('Faa,isa Yuusuf Hussein', '616585451', ['required_training_days' => 30]);

        $register = [
            ['1.7.2026', 'Faa,isa Yuusuf Hussein', '616585451', 'Waaberi', '110', '15Maalin', '4'],
            ['1.7.2026', 'Cabdi Cusub', '619000111', 'Hodan', '80', 'Bil', '6'],
            ['1.7.2026', 'Si,ida Abdullahi', '616999250', '', '0', '20 Maalin', 'complate'],
        ];

        $first = $this->sync($register);

        $this->assertSame(2, $first['created']);
        $this->assertSame(1, $first['updated']);

        $snapshot = DB::table('students')->orderBy('id')->get()
            ->map(fn ($r) => array_diff_key((array) $r, ['updated_at' => null]))->all();
        $payments = DB::table('student_payments')->count();

        $plan = $this->plan($register);

        foreach ($plan['rows'] as $row) {
            $this->assertSame(AlphaSchoolSyncService::NO_CHANGE, $row['decision'],
                "{$row['name']} would change again on a second run");
        }

        $second = app(AlphaSchoolSyncService::class)->apply($plan, $this->admin);

        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame($payments, DB::table('student_payments')->count());
        $this->assertSame($snapshot, DB::table('students')->orderBy('id')->get()
            ->map(fn ($r) => array_diff_key((array) $r, ['updated_at' => null]))->all());
    }

    public function test_the_real_sync_refuses_to_run_without_being_asked(): void
    {
        $path = $this->register([['1.7.2026', 'Cabdi Cusub', '619000111', '', '0', 'Bil', '']]);

        $this->assertSame(1, Artisan::call('alpha-school:sync', ['--file' => $path]));
        $this->assertStringContainsString('Refusing to guess', Artisan::output());
        $this->assertSame(0, Student::count());
    }

    /* ------------------------------------------------------------------
       Helpers
       ------------------------------------------------------------------ */

    /**
     * A student on file.
     *
     * makeStudent only writes the handful of columns it knows about, so
     * anything else a test needs — an address, a fee — is put on afterwards.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function student(string $name, string $phone, array $attributes = []): Student
    {
        $student = $this->makeStudent($name, null, ['phone' => '+252'.$phone] + $attributes);

        $extra = array_diff_key($attributes, array_flip([
            'phone', 'start_date', 'required_training_days', 'status',
        ]));

        if ($extra !== []) {
            $student->forceFill($extra)->save();
        }

        return $student->refresh();
    }

    /** @param  array<int, array<int, string>>  $rows */
    private function plan(array $rows, bool $allowReopen = false): array
    {
        return app(AlphaSchoolSyncService::class)->plan($this->register($rows), 'Sheet1', $allowReopen);
    }

    /** @param  array<int, array<int, string>>  $rows */
    private function planRow(array $rows): array
    {
        return $this->plan($rows)['rows'][0];
    }

    /** @param  array<int, array<int, string>>  $rows */
    private function sync(array $rows, bool $allowReopen = false): array
    {
        return app(AlphaSchoolSyncService::class)->apply($this->plan($rows, $allowReopen), $this->admin);
    }

    /**
     * A register holding the rows given.
     *
     * @param  array<int, array<int, string>>  $rows  [date, name, phone, address, paid, duration, remaining]
     */
    private function register(array $rows): string
    {
        $headings = ['TAARIIKHDA', 'T/T/', 'MAGACA SEDDEXEN', 'LAMBARKA', 'DEGMADA',
            'LACAGTA BAXSHEY', 'LACAGTA HARAA', 'Mudadda', 'Column1'];

        $sheet = '<row r="1">';

        foreach ($headings as $index => $heading) {
            $sheet .= $this->cell(chr(65 + $index).'1', $heading);
        }

        $sheet .= '</row>';

        foreach ($rows as $index => [$date, $name, $phone, $address, $paid, $duration, $remaining]) {
            $r = $index + 2;

            $sheet .= '<row r="'.$r.'">'
                .$this->cell('A'.$r, $date)
                .$this->cell('B'.$r, (string) ($index + 1))
                .$this->cell('C'.$r, $name)
                .$this->cell('D'.$r, $phone)
                .($address === '' ? '' : $this->cell('E'.$r, $address))
                .$this->cell('F'.$r, $paid)
                .$this->cell('G'.$r, 'NONE')
                .$this->cell('H'.$r, $duration)
                .($remaining === '' ? '' : $this->cell('I'.$r, $remaining))
                .'</row>';
        }

        $path = tempnam(sys_get_temp_dir(), 'register').'.xlsx';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->close();

        return $path;
    }

    private function cell(string $reference, string $value): string
    {
        return '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'
            .htmlspecialchars($value, ENT_XML1).'</t></is></c>';
    }
}
