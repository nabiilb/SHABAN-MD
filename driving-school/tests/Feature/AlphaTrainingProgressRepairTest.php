<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Services\AlphaTrainingProgressRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

/**
 * Repairing the two training columns on students already imported.
 *
 * The register keeps the length of the course and the days still to run apart,
 * in column H ("Mudadda") and column I, and the whole point of
 * these is that one is never read as the other: a blank remaining column must
 * not hand a student their own course length back as a balance, and a course
 * length must never be mistaken for days left.
 *
 * Each test writes the register it is about, so the two columns are visible in
 * the test itself rather than buried in a fixture.
 */
class AlphaTrainingProgressRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-20 09:00:00'));
        $this->makeUser(Role::ADMIN);
    }

    /* ------------------------------------------------------------------
       Reading the two columns apart
       ------------------------------------------------------------------ */

    public function test_fifteen_days_with_ten_left_is_fifteen_required_and_ten_remaining(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030', ['required_training_days' => 15]);

        $this->repair([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $student->refresh();

        $this->assertSame(15, $student->required_training_days);
        $this->assertSame(10, $student->opening_remaining_days);
        $this->assertSame(10, $student->remaining_days);
        $this->assertSame(5, $student->effective_completed_days);
        $this->assertSame(33.3, $student->progress_percentage);
    }

    public function test_a_month_with_thirteen_left_is_thirty_required_and_thirteen_remaining(): void
    {
        $student = $this->student('Sakariye Bashir Maxamed', '612228966', ['required_training_days' => 30]);

        $this->repair([['Sakariye Bashir Maxamed', '612228966', 'Bil', '13']]);

        $student->refresh();

        $this->assertSame(30, $student->required_training_days);
        $this->assertSame(13, $student->opening_remaining_days);
        $this->assertSame(13, $student->remaining_days);
        $this->assertSame(17, $student->effective_completed_days);
        $this->assertSame(56.7, $student->progress_percentage);
    }

    public function test_a_month_with_four_left_is_thirty_required_and_four_remaining(): void
    {
        $student = $this->student('Canab Siciid Ugaas', '615020052');

        $this->repair([['Canab Siciid Ugaas', '615020052', 'Bil', '4']]);

        $student->refresh();

        $this->assertSame(30, $student->required_training_days);
        $this->assertSame(4, $student->opening_remaining_days);
        $this->assertSame(4, $student->remaining_days);
        $this->assertSame(26, $student->effective_completed_days);
        $this->assertSame(86.7, $student->progress_percentage);
    }

    public function test_the_completion_word_finishes_a_student_without_inventing_attendance(): void
    {
        $student = $this->student('Mahad Hussein Raage', '618629168');

        $this->repair([['Mahad Hussein Raage', '618629168', '15Maalin', 'complate']]);

        $student->refresh();

        $this->assertSame('completed', $student->status);
        $this->assertSame(15, $student->required_training_days);
        $this->assertSame(0, $student->remaining_days);
        $this->assertSame(15, $student->effective_completed_days);
        $this->assertSame(100.0, $student->progress_percentage);
        $this->assertSame(0, Attendance::count());
    }

    /**
     * The shape of the production mismatch, stated on its own.
     *
     * Column H and column I are both present and say different things. The
     * balance has to come from I. If it ever comes from H the student shows a
     * whole course still ahead of them, which is the symptom that started
     * this.
     */
    public function test_column_h_is_never_written_into_the_remaining_balance(): void
    {
        $pairs = [
            // [duration in H, remaining in I, required, remaining, completed, progress]
            ['15Maalin', '10', 15, 10, 5, 33.3],
            ['15 Maalin', '9', 15, 9, 6, 40.0],
            ['Bil', '13', 30, 13, 17, 56.7],
            ['Bil', '4', 30, 4, 26, 86.7],
            ['10Maalin', '7', 10, 7, 3, 30.0],
            ['20 maalin', '5', 20, 5, 15, 75.0],
        ];

        foreach ($pairs as $index => [$h, $i, $required, $remaining, $completed, $progress]) {
            $phone = '61400000'.$index;
            $student = $this->student('Student '.$index, $phone, ['required_training_days' => 24]);

            $this->repair([['Student '.$index, $phone, $h, $i]]);

            $student->refresh();
            $where = "H={$h} I={$i}";

            $this->assertSame($required, $student->required_training_days, "{$where}: H is the course length");
            $this->assertSame($remaining, $student->opening_remaining_days, "{$where}: I is the balance");
            $this->assertSame($remaining, $student->remaining_days, $where);
            $this->assertSame($completed, $student->effective_completed_days, $where);
            $this->assertSame($progress, $student->progress_percentage, $where);
            $this->assertNotSame(
                $student->required_training_days,
                $student->remaining_days,
                "{$where}: the course length has been handed back as the balance",
            );
        }
    }

    public function test_the_completion_word_leaves_nothing_left_to_run(): void
    {
        $student = $this->student('Sumayo Sharif Maxamed', '615111333');

        $this->repair([['Sumayo Sharif Maxamed', '615111333', 'Bil', 'complate']]);

        $student->refresh();

        $this->assertSame('completed', $student->status);
        $this->assertSame(0, $student->opening_remaining_days, 'not the course length, and not null');
        $this->assertSame(30, $student->required_training_days);
        $this->assertSame(0, $student->remaining_days);
        $this->assertSame(30, $student->effective_completed_days);
        $this->assertSame(100.0, $student->progress_percentage);
    }

    public function test_a_blank_remaining_column_never_becomes_the_course_length(): void
    {
        $student = $this->student('Maxamed Muxumed Maxamed', '618838610', ['required_training_days' => 24]);

        $this->repair([['Maxamed Muxumed Maxamed', '618838610', '15Maalin', '']]);

        $student->refresh();

        // The course length is corrected from the register…
        $this->assertSame(15, $student->required_training_days);
        // …and the balance is left for attendance to work out, as before.
        $this->assertNull($student->opening_remaining_days);
        $this->assertNull($student->opening_remaining_from);
        $this->assertSame('active', $student->status);
    }

    public function test_a_blank_remaining_column_leaves_a_finished_student_finished(): void
    {
        $student = $this->student('Sumayo Sharif Maxamed', '615111222', ['status' => 'completed']);

        $this->repair([['Sumayo Sharif Maxamed', '615111222', 'Bil', '']]);

        // The register saying nothing is not the register saying "active".
        $this->assertSame('completed', $student->fresh()->status);
    }

    public function test_a_remaining_column_it_cannot_read_is_reported_and_left_alone(): void
    {
        $student = $this->student('Luqman Abdillhi A/rahman', '614186046');

        $plan = $this->plan([['Luqman Abdillhi A/rahman', '614186046', 'Bil', '5/']]);
        $row = $plan['rows'][0];

        $this->assertSame(AlphaTrainingProgressRepair::NEEDS_REVIEW, $row['decision']);
        $this->assertStringContainsString('5/', $row['reason']);
        $this->assertSame([], $row['changes']);

        $this->apply($plan);

        $this->assertNull($student->fresh()->opening_remaining_days);
    }

    public function test_more_days_left_than_the_course_has_is_reported_and_left_alone(): void
    {
        $student = $this->student('Nimco Cismaan Cilmi', '615333444');

        $plan = $this->plan([['Nimco Cismaan Cilmi', '615333444', '15Maalin', '20']]);
        $row = $plan['rows'][0];

        $this->assertSame(AlphaTrainingProgressRepair::NEEDS_REVIEW, $row['decision']);
        $this->assertStringContainsString('more days than the course has', $row['reason']);

        $this->apply($plan);

        $this->assertNull($student->fresh()->opening_remaining_days);
    }

    public function test_a_register_row_with_nobody_on_file_is_reported_not_created(): void
    {
        $before = Student::withTrashed()->count();

        $plan = $this->plan([['Cabdi Nobody', '619000111', '15Maalin', '10']]);

        $this->assertSame(AlphaTrainingProgressRepair::NOT_MATCHED, $plan['rows'][0]['decision']);

        $this->apply($plan);

        $this->assertSame($before, Student::withTrashed()->count());
    }

    /* ------------------------------------------------------------------
       What the repair must not touch
       ------------------------------------------------------------------ */

    public function test_it_creates_no_student_no_attendance_and_no_payment(): void
    {
        $this->student('Nasteexo Sadak', '614503030');

        $before = [
            'students' => Student::withTrashed()->count(),
            'attendance' => Attendance::withTrashed()->count(),
            'payments' => StudentPayment::withTrashed()->count(),
        ];

        $this->repair([
            ['Nasteexo Sadak', '614503030', '15Maalin', '10'],
            ['Cabdi Nobody', '619000111', 'Bil', '4'],
        ]);

        $this->assertSame($before['students'], Student::withTrashed()->count());
        $this->assertSame($before['attendance'], Attendance::withTrashed()->count());
        $this->assertSame($before['payments'], StudentPayment::withTrashed()->count());
    }

    public function test_it_leaves_the_money_alone(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030', ['total_fee' => 120]);

        $payment = StudentPayment::create([
            'payment_number' => 'PAY-9001',
            'student_id' => $student->id,
            'amount' => 60,
            'payment_date' => '2026-08-01',
            'payment_method' => 'cash',
            'reference' => 'ALPHA-SCHOOL-REGISTER:9',
        ]);

        $paymentBefore = $payment->fresh()->getAttributes();
        $feeBefore = $student->fresh()->total_fee;

        $this->repair([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
        $this->assertSame($feeBefore, $student->fresh()->total_fee);
        $this->assertSame(1, StudentPayment::count());
    }

    public function test_it_changes_only_the_four_columns_it_is_for(): void
    {
        $instructor = $this->makeInstructor('Cali Teacher');
        $student = $this->student('Nasteexo Sadak', '614503030', ['required_training_days' => 24]);
        $student->forceFill([
            'current_instructor_id' => $instructor->id,
            'address' => 'Yaaqshid',
            'notes' => 'Do not lose this',
            'total_fee' => 120,
        ])->save();

        $before = DB::table('students')->where('id', $student->id)->first();

        $this->repair([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $after = DB::table('students')->where('id', $student->id)->first();

        $changed = array_keys(array_diff_assoc(
            array_diff_key((array) $after, ['updated_at' => null]),
            (array) $before,
        ));

        sort($changed);

        $this->assertSame(['opening_remaining_days', 'opening_remaining_from', 'required_training_days'], $changed);
        $this->assertSame($instructor->id, $student->fresh()->current_instructor_id);
        $this->assertSame('Do not lose this', $student->fresh()->notes);
    }

    /* ------------------------------------------------------------------
       Running it twice
       ------------------------------------------------------------------ */

    public function test_running_it_again_changes_nothing(): void
    {
        $this->student('Nasteexo Sadak', '614503030');
        $this->student('Mahad Hussein Raage', '618629168');

        $register = [
            ['Nasteexo Sadak', '614503030', '15Maalin', '10'],
            ['Mahad Hussein Raage', '618629168', '15Maalin', 'complate'],
        ];

        $first = $this->repair($register);
        $this->assertSame(2, $first['updated']);

        $snapshot = DB::table('students')->orderBy('id')->get()->map(
            fn ($row) => array_diff_key((array) $row, ['updated_at' => null]),
        )->all();

        $plan = $this->plan($register);

        foreach ($plan['rows'] as $row) {
            $this->assertSame(AlphaTrainingProgressRepair::NO_CHANGE, $row['decision']);
            $this->assertSame([], $row['changes']);
        }

        $second = $this->apply($plan);

        $this->assertSame(0, $second['updated']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertSame($snapshot, DB::table('students')->orderBy('id')->get()->map(
            fn ($row) => array_diff_key((array) $row, ['updated_at' => null]),
        )->all());
    }

    public function test_the_baseline_does_not_drift_on_a_later_run(): void
    {
        $this->student('Nasteexo Sadak', '614503030');

        $this->repair([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $baseline = Student::where('phone', '+252614503030')->value('opening_remaining_from');

        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00'));

        $this->repair([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $this->assertEquals($baseline, Student::where('phone', '+252614503030')->value('opening_remaining_from'));
    }

    /* ------------------------------------------------------------------
       How the balance is counted down
       ------------------------------------------------------------------ */

    public function test_training_after_the_baseline_counts_down_the_balance(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030');

        $this->repair([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $this->attend($student, ['2026-09-21', '2026-09-22', '2026-09-23']);

        $student->refresh();

        $this->assertSame(7, $student->remaining_days);
        $this->assertSame(8, $student->effective_completed_days);
        $this->assertSame(53.3, $student->progress_percentage);
    }

    public function test_training_before_the_baseline_does_not_count_against_the_balance(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030');

        // Days from before the register was written: already behind the ten.
        $this->attend($student, ['2026-08-01', '2026-08-02', '2026-09-19']);

        $this->repair([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $student->refresh();

        $this->assertSame('2026-09-20', $student->opening_remaining_from->toDateString());
        $this->assertSame(10, $student->remaining_days);
        $this->assertSame(5, $student->effective_completed_days);
    }

    public function test_two_check_ins_on_one_day_count_as_one_day(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030');

        $this->repair([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $this->attend($student, ['2026-09-21', '2026-09-21', '2026-09-22']);

        $this->assertSame(8, $student->fresh()->remaining_days);
    }

    public function test_only_days_actually_trained_count_against_the_balance(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030');

        $this->repair([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $this->attend($student, ['2026-09-21']);
        $this->attend($student, ['2026-09-22', '2026-09-23'], 'absent');
        $this->attend($student, ['2026-09-24'], 'excused');

        $this->assertSame(9, $student->fresh()->remaining_days);
    }

    /* ------------------------------------------------------------------
       The command
       ------------------------------------------------------------------ */

    public function test_a_dry_run_writes_nothing(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030', ['required_training_days' => 24]);
        $before = $student->fresh()->getAttributes();

        $path = $this->register([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $this->artisan('alpha-school:repair-training-progress', ['--file' => $path, '--dry-run' => true])
            ->expectsOutputToContain('NO DATABASE CHANGES WERE MADE')
            ->assertSuccessful();

        $this->assertSame($before, $student->fresh()->getAttributes());
    }

    public function test_the_repair_refuses_to_run_without_being_asked(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030', ['required_training_days' => 24]);
        $path = $this->register([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $this->artisan('alpha-school:repair-training-progress', ['--file' => $path])
            ->expectsOutputToContain('Refusing to guess')
            ->assertFailed();

        $this->artisan('alpha-school:repair-training-progress', [
            '--file' => $path, '--dry-run' => true, '--confirm' => true,
        ])->expectsOutputToContain('not both')->assertFailed();

        $this->assertSame(24, $student->fresh()->required_training_days);
    }

    /**
     * --confirm asks nothing, because there is nowhere to ask from.
     *
     * A deploy page in public_html reaches this through $kernel->call(), where
     * PHP defines no STDIN: a question there is not a prompt nobody answers,
     * it is a fatal that stops the request with nothing written and no exit
     * code. This test passes no expectsConfirmation, so it fails outright if
     * the command asks anything at all.
     */
    public function test_confirm_writes_without_asking_anything(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030', ['required_training_days' => 24]);
        $path = $this->register([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $this->artisan('alpha-school:repair-training-progress', ['--file' => $path, '--confirm' => true])
            ->expectsOutputToContain('Students corrected:   1')
            ->assertSuccessful();

        $student->refresh();

        $this->assertSame(15, $student->required_training_days);
        $this->assertSame(10, $student->opening_remaining_days);
    }

    /**
     * The same call the deploy page makes, options and all.
     *
     * Artisan::call is $kernel->call: an ArrayInput that is still marked
     * interactive, with no terminal behind it.
     */
    public function test_it_runs_through_the_console_kernel_with_no_terminal(): void
    {
        $student = $this->student('Nasteexo Sadak', '614503030', ['required_training_days' => 24]);
        $path = $this->register([['Nasteexo Sadak', '614503030', '15Maalin', '10']]);

        $exitCode = Artisan::call('alpha-school:repair-training-progress', [
            '--file' => $path,
            '--confirm' => true,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('REPAIR COMPLETE', $output);
        $this->assertStringContainsString('Students corrected:   1', $output);
        $this->assertSame(10, $student->fresh()->opening_remaining_days);

        // And again, with nothing left to do.
        $this->assertSame(0, Artisan::call('alpha-school:repair-training-progress', [
            '--file' => $path, '--confirm' => true,
        ]));
        $this->assertStringContainsString('already matches the register', Artisan::output());
        $this->assertSame(10, $student->fresh()->opening_remaining_days);
    }

    /* ------------------------------------------------------------------
       Helpers
       ------------------------------------------------------------------ */

    /** @param  array<int, array<int, string>>  $register */
    private function repair(array $register): array
    {
        return $this->apply($this->plan($register));
    }

    /**
     * @param  array<int, array<int, string>>  $register
     * @return array<string, mixed>
     */
    private function plan(array $register): array
    {
        return app(AlphaTrainingProgressRepair::class)->plan($this->register($register), 'Sheet1');
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function apply(array $plan): array
    {
        return app(AlphaTrainingProgressRepair::class)->apply($plan);
    }

    /** @param  array<string, mixed>  $attributes */
    private function student(string $name, string $phone, array $attributes = []): Student
    {
        return $this->makeStudent($name, null, ['phone' => '+252'.$phone] + $attributes);
    }

    /** @param  array<int, string>  $dates */
    private function attend(Student $student, array $dates, string $status = 'present'): void
    {
        foreach ($dates as $date) {
            Attendance::create([
                'student_id' => $student->id,
                'attendance_date' => $date,
                'status' => $status,
                'check_in_time' => '09:00:00',
            ]);
        }
    }

    /**
     * Writes a register holding the rows given, in the columns the real one
     * uses: the name in C, the phone in D, the duration in H and the
     * remaining/completion column in I.
     *
     * @param  array<int, array<int, string>>  $rows  [name, phone, duration, remaining]
     */
    private function register(array $rows): string
    {
        $sheet = '<row r="1">'
            .$this->cell('A1', 'TAARIIKHDA').$this->cell('B1', 'T/T/').$this->cell('C1', 'MAGACA SEDDEXEN')
            .$this->cell('D1', 'LAMBARKA').$this->cell('E1', 'DEGMADA').$this->cell('F1', 'LACAGTA BAXSHEY')
            .$this->cell('G1', 'LACAGTA HARAA').$this->cell('H1', 'Mudadda').$this->cell('I1', 'Column1')
            .'</row>';

        foreach ($rows as $index => [$name, $phone, $duration, $remaining]) {
            $r = $index + 2;

            $sheet .= '<row r="'.$r.'">'
                .$this->cell('A'.$r, '2026-07-01')
                .$this->cell('B'.$r, (string) ($index + 1))
                .$this->cell('C'.$r, $name)
                .$this->cell('D'.$r, $phone)
                .$this->cell('E'.$r, 'Yaaqshid')
                .$this->cell('F'.$r, '0')
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

    /** One inline-string cell; the register is text throughout. */
    private function cell(string $reference, string $value): string
    {
        return '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'
            .htmlspecialchars($value, ENT_XML1).'</t></is></c>';
    }
}
