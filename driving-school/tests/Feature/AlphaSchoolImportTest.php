<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Services\AlphaSchoolImporter;
use App\Support\XlsxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Importing the school's own register.
 *
 * The fixture is the real workbook, so the parse is tested against the data it
 * actually has to survive: dates written three ways, "NONE" for nothing owed,
 * "complate" for finished, section totals in the middle, and a student with no
 * phone number.
 */
class AlphaSchoolImportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-14 09:00:00'));

        $this->makeUser(Role::ADMIN);

        $this->path = storage_path('app/imports/ALPHA SCHOOL.xlsx');

        if (! is_readable($this->path)) {
            $this->markTestSkipped('The Alpha School register is not present in storage/app/imports.');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function importer(): AlphaSchoolImporter
    {
        return app(AlphaSchoolImporter::class);
    }

    /* 9 — the dry run changes nothing. */
    public function test_a_dry_run_writes_nothing_at_all(): void
    {
        $this->artisan('alpha-school:import', ['--dry-run' => true])
            ->expectsOutputToContain('NO DATABASE CHANGES WERE MADE')
            ->assertSuccessful();

        $this->assertSame(0, Student::count());
        $this->assertSame(0, StudentPayment::count());
    }

    public function test_the_dry_run_reports_what_it_found(): void
    {
        $plan = $this->importer()->parse($this->path);

        $this->assertGreaterThan(100, count(array_filter($plan['records'], fn ($r) => $r['action'] === 'create')));
        $this->assertNotEmpty($plan['notices']['duplicates_in_file']);
        $this->assertSame(0, Student::count());
    }

    /* 10 — the real import creates students. */
    public function test_the_import_creates_the_register(): void
    {
        $plan = $this->importer()->parse($this->path);
        $result = $this->importer()->apply($plan);

        $this->assertSame(0, count($result['failures']));
        $this->assertGreaterThan(100, $result['created']);
        $this->assertSame($result['created'], Student::count());

        $student = Student::where('phone', '+252616585451')->firstOrFail();

        $this->assertSame('Faa,isa Yuusuf Hussein', $student->full_name);
        $this->assertSame('Waaberi', $student->address);
        $this->assertSame('2026-07-06', $student->start_date->toDateString());
        $this->assertSame(30, $student->required_training_days);   // "Bil" — a month
        $this->assertSame('110.00', $student->total_fee);          // 110 paid, NONE owed
        $this->assertNull($student->email);
        $this->assertNull($student->date_of_birth);
        $this->assertStringStartsWith('STD-', $student->student_number);
    }

    /* 11 + 12 — running it twice changes nothing the second time. */
    public function test_a_second_run_duplicates_neither_students_nor_payments(): void
    {
        $this->importer()->apply($this->importer()->parse($this->path));

        $students = Student::count();
        $payments = StudentPayment::count();

        $second = $this->importer()->apply($this->importer()->parse($this->path));

        $this->assertSame($students, Student::count());
        $this->assertSame($payments, StudentPayment::count());
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['payments']);
        $this->assertGreaterThan(0, $second['payments_existing']);
    }

    /* 13 — "complate" is a finished student. */
    public function test_complate_is_read_as_completed(): void
    {
        $this->importer()->apply($this->importer()->parse($this->path));

        $student = Student::where('phone', '+252614411009')->firstOrFail();

        $this->assertSame('completed', $student->status);
        $this->assertGreaterThan(0, Student::where('status', 'completed')->count());
    }

    /* 14 — a bad row is reported, not fatal. */
    public function test_unusable_rows_are_reported_and_the_rest_still_import(): void
    {
        $plan = $this->importer()->parse($this->path);

        $skipped = array_values(array_filter($plan['records'], fn ($r) => $r['action'] === 'skip'));
        $reasons = array_column($skipped, 'reason');

        $this->assertNotEmpty($skipped);
        $this->assertTrue((bool) array_filter($reasons, fn ($r) => str_contains($r, 'Section total')));
        $this->assertTrue((bool) array_filter($reasons, fn ($r) => str_contains($r, 'No phone number')));
        $this->assertTrue((bool) array_filter($reasons, fn ($r) => str_contains($r, 'Same phone as row')));

        // And the good rows still land.
        $result = $this->importer()->apply($plan);
        $this->assertGreaterThan(100, $result['created']);
        $this->assertSame(count($skipped), $result['skipped']);
    }

    /* 4 — payments come from LACAGTA BAXSHEY and carry the row they came from. */
    public function test_payments_are_created_only_for_money_actually_paid(): void
    {
        $this->importer()->apply($this->importer()->parse($this->path));

        $student = Student::where('phone', '+252616869041')->firstOrFail();
        $payment = $student->payments()->firstOrFail();

        // 70 paid, 40 still owed.
        $this->assertSame('70.00', $payment->amount);
        $this->assertSame('110.00', $student->total_fee);
        $this->assertSame('70.00', number_format($student->total_paid, 2));
        $this->assertSame(40.0, $student->balance);
        $this->assertSame('cash', $payment->payment_method);
        $this->assertStringStartsWith(AlphaSchoolImporter::SOURCE.':', $payment->reference);
        $this->assertSame('2026-07-12', $payment->payment_date->toDateString());

        // Nobody is banked for nothing.
        $this->assertSame(0, StudentPayment::where('amount', '<=', 0)->count());
    }

    /** "NONE", however it is spelled and spaced, means nothing outstanding. */
    public function test_none_means_zero_owed(): void
    {
        $this->importer()->apply($this->importer()->parse($this->path));

        $student = Student::where('phone', '+252616999250')->firstOrFail();

        $this->assertSame('80.00', $student->total_fee);
        $this->assertSame(0.0, $student->balance);
        $this->assertSame(20, $student->required_training_days);
    }

    /** The reader itself, on the workbook it was written for. */
    public function test_the_reader_finds_the_register_sheet(): void
    {
        $this->assertContains('Sheet1', XlsxReader::sheetNames($this->path));

        $rows = XlsxReader::rows($this->path, 'Sheet1');

        $this->assertSame('MAGACA SEDDEXEN', $rows[1]['C']);
        $this->assertSame('TAARIIKHDA', $rows[1]['A']);
    }
}
