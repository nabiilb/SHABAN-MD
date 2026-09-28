<?php

namespace Tests\Feature;

use App\Services\AlphaSchoolImporter;
use App\Support\XlsxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

/**
 * Reading the register out of the file, whatever wrote the file.
 *
 * Excel numbers every row, references every cell in upper case and saves the
 * last calculated value beside every formula. Nothing else is obliged to.
 * Google Sheets, LibreOffice run headless and any script that touches the
 * workbook may leave the r attributes off entirely or write a formula with no
 * result cached against it, and a reader that assumes Excel's habits reports
 * those columns as empty — which is the one answer that is certainly wrong
 * when somebody is looking at the number in the cell.
 *
 * Every case here is the same row: column H says 15Maalin, column I says 10.
 */
class AlphaWorkbookReadingTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
       The shapes a register arrives in
       ------------------------------------------------------------------ */

    public function test_it_reads_a_number_excel_wrote_plainly(): void
    {
        $this->assertReadsTenDaysFrom('<c r="I113"><v>10</v></c>');
    }

    public function test_it_reads_a_number_typed_as_text(): void
    {
        $this->assertReadsTenDaysFrom('<c r="I113" t="inlineStr"><is><t>10</t></is></c>');
    }

    public function test_it_reads_a_number_with_its_type_spelled_out(): void
    {
        $this->assertReadsTenDaysFrom('<c r="I113" t="n"><v>10</v></c>');
    }

    public function test_it_reads_a_formula_that_kept_its_answer(): void
    {
        $this->assertReadsTenDaysFrom('<c r="I113"><f>15-5</f><v>10</v></c>');
    }

    public function test_it_reads_cells_referenced_in_lower_case(): void
    {
        $this->assertReadsTenDaysFrom('<c r="i113"><v>10</v></c>', duration: '<c r="h113" t="inlineStr"><is><t>15Maalin</t></is></c>');
    }

    public function test_it_reads_a_row_that_was_never_numbered(): void
    {
        // The row has no r; its cells still say which row they are on.
        $path = $this->workbook('<row>'
            .'<c r="A113" t="inlineStr"><is><t>2026-07-01</t></is></c>'
            .'<c r="C113" t="inlineStr"><is><t>Nasteexo Sadak</t></is></c>'
            .'<c r="H113" t="inlineStr"><is><t>15Maalin</t></is></c>'
            .'<c r="I113"><v>10</v></c>'
            .'</row>');

        $rows = XlsxReader::rows($path, 'Sheet1');

        $this->assertSame('15Maalin', $rows[113]['H']);
        $this->assertSame(10.0, $rows[113]['I']);
    }

    public function test_it_reads_cells_that_were_never_referenced(): void
    {
        // No r anywhere: position is the only thing saying which column is which.
        $cells = '';

        foreach (['2026-07-01', '', 'Nasteexo Sadak', '', '', '', '', '15Maalin'] as $value) {
            $cells .= '<c t="inlineStr"><is><t>'.$value.'</t></is></c>';
        }

        $path = $this->workbook('<row r="113">'.$cells.'<c><v>10</v></c></row>');

        $rows = XlsxReader::rows($path, 'Sheet1');

        $this->assertSame('15Maalin', $rows[113]['H']);
        $this->assertSame(10.0, $rows[113]['I']);
    }

    /* ------------------------------------------------------------------
       The one that cannot be read
       ------------------------------------------------------------------ */

    public function test_a_formula_with_no_saved_answer_is_reported_not_swallowed(): void
    {
        $path = $this->workbook('<row r="113">'
            .'<c r="A113" t="inlineStr"><is><t>2026-07-01</t></is></c>'
            .'<c r="C113" t="inlineStr"><is><t>Nasteexo Sadak</t></is></c>'
            .'<c r="D113" t="inlineStr"><is><t>614503030</t></is></c>'
            .'<c r="H113" t="inlineStr"><is><t>15Maalin</t></is></c>'
            .'<c r="I113"><f>15-5</f></c>'
            .'</row>');

        // The cell comes back as the formula it holds, not as empty…
        $this->assertSame('=15-5', XlsxReader::rows($path, 'Sheet1')[113]['I']);

        // …so the importer reports it rather than reading the row as having
        // no balance at all.
        $plan = app(AlphaSchoolImporter::class)->parse($path, 'Sheet1');
        $reported = collect($plan['notices']['remaining_not_understood']);

        $this->assertCount(1, $reported);
        $this->assertSame('=15-5', $reported->first()['value']);

        $record = collect($plan['records'])->firstWhere('row', 113);
        $this->assertNull($record['source']['remaining_days']);
        $this->assertSame(15, $record['student']['required_training_days']);
    }

    public function test_a_truly_empty_cell_is_still_empty(): void
    {
        // Excel keeps styled cells that hold nothing. They are not formulas,
        // and they must not start reading as anything else.
        $path = $this->workbook('<row r="113">'
            .'<c r="C113" t="inlineStr"><is><t>Nasteexo Sadak</t></is></c>'
            .'<c r="H113" t="inlineStr"><is><t>15Maalin</t></is></c>'
            .'<c r="I113" s="14"/>'
            .'</row>');

        $this->assertNull(XlsxReader::rows($path, 'Sheet1')[113]['I'] ?? null);
    }

    /* ------------------------------------------------------------------
       Looking at one cell
       ------------------------------------------------------------------ */

    public function test_it_describes_a_cell_as_the_file_has_it(): void
    {
        $path = $this->workbook('<row r="113">'
            .'<c r="H113" t="inlineStr"><is><t>15Maalin</t></is></c>'
            .'<c r="I113"><f>15-5</f></c>'
            .'</row>');

        $duration = XlsxReader::describeCell($path, 'Sheet1', 'H113');
        $remaining = XlsxReader::describeCell($path, 'Sheet1', 'I113');

        $this->assertTrue($duration['found']);
        $this->assertSame('15Maalin', $duration['value']);

        $this->assertTrue($remaining['found']);
        $this->assertSame('15-5', $remaining['formula']);
        $this->assertNull($remaining['raw'], 'the answer was never saved with the formula');

        $this->assertFalse(XlsxReader::describeCell($path, 'Sheet1', 'Z999')['found']);
    }

    /* ------------------------------------------------------------------
       The diagnostic
       ------------------------------------------------------------------ */

    public function test_the_diagnostic_reports_the_file_the_sheet_and_the_cells(): void
    {
        $path = $this->register();

        $writes = [];

        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $exitCode = Artisan::call('alpha-school:diagnose-workbook', [
            '--file' => $path, '--sheet' => 'Sheet1', '--row' => 113, '--student' => 'Nasteexo',
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        // 1-4: the file, by every name that could tell two copies apart.
        $this->assertStringContainsString($path, $output);
        $this->assertStringContainsString((string) number_format(filesize($path)), $output);
        $this->assertStringContainsString(hash_file('sha256', $path), $output);
        $this->assertStringContainsString('Sheet1', $output);

        // 5-6: the two cells, and the row as the importer reads it.
        $this->assertStringContainsString('15Maalin', $output);
        $this->assertStringContainsString('Nasteexo Sadak', $output);

        // 7: and what the whole column adds up to.
        $this->assertStringContainsString('whole number', $output);
        $this->assertStringContainsString('completion word', $output);
        $this->assertStringContainsString('malformed', $output);
        $this->assertStringContainsString('The repair has balances to apply', $output);

        $this->assertSame([], $writes, 'The diagnostic issued a write: '.implode(' | ', $writes));
    }

    public function test_the_diagnostic_says_so_when_the_column_holds_no_numbers(): void
    {
        $path = $this->register(remaining: 'complate');

        Artisan::call('alpha-school:diagnose-workbook', ['--file' => $path, '--sheet' => 'Sheet1']);

        $this->assertStringContainsString('Not one row in column I of this sheet holds a number', Artisan::output());
    }

    public function test_the_diagnostic_names_every_sheet_so_the_wrong_one_shows_up(): void
    {
        $path = $this->register(sheets: ['Sheet1', 'Corrected', 'Sheet3']);

        Artisan::call('alpha-school:diagnose-workbook', ['--file' => $path, '--sheet' => 'Sheet1']);

        $output = Artisan::output();

        foreach (['Sheet1', 'Corrected', 'Sheet3'] as $name) {
            $this->assertStringContainsString($name, $output);
        }
    }

    public function test_the_diagnostic_reports_a_missing_file_rather_than_throwing(): void
    {
        $this->assertSame(1, Artisan::call('alpha-school:diagnose-workbook', [
            '--file' => '/no/such/register.xlsx',
        ]));

        $this->assertStringContainsString('no readable workbook', Artisan::output());
    }

    /* ------------------------------------------------------------------
       Helpers
       ------------------------------------------------------------------ */

    /** Asserts a register row reads as 15 required days with 10 left. */
    private function assertReadsTenDaysFrom(string $remaining, ?string $duration = null): void
    {
        $path = $this->workbook('<row r="113">'
            .'<c r="A113" t="inlineStr"><is><t>2026-07-01</t></is></c>'
            .'<c r="C113" t="inlineStr"><is><t>Nasteexo Sadak</t></is></c>'
            .'<c r="D113" t="inlineStr"><is><t>614503030</t></is></c>'
            .($duration ?? '<c r="H113" t="inlineStr"><is><t>15Maalin</t></is></c>')
            .$remaining
            .'</row>');

        $record = collect(app(AlphaSchoolImporter::class)->parse($path, 'Sheet1')['records'])
            ->firstWhere('row', 113);

        $this->assertSame('15Maalin', $record['source']['duration_raw']);
        $this->assertSame(15, $record['student']['required_training_days'], 'column H is the course length');
        $this->assertSame(10, $record['source']['remaining_days'], 'column I is the balance');
        $this->assertSame(10, $record['student']['opening_remaining_days']);
    }

    /** A register with the header row and one student at row 113. */
    private function register(string $remaining = '10', array $sheets = ['Sheet1']): string
    {
        $header = '<row r="1">';

        foreach (['TAARIIKHDA', 'T/T/', 'MAGACA SEDDEXEN', 'LAMBARKA', 'DEGMADA',
            'LACAGTA BAXSHEY', 'LACAGTA HARAA', 'Mudadda', 'Column1'] as $index => $heading) {
            $header .= '<c r="'.chr(65 + $index).'1" t="inlineStr"><is><t>'.$heading.'</t></is></c>';
        }

        $header .= '</row>';

        $row = '<row r="113">'
            .'<c r="A113" t="inlineStr"><is><t>2026-07-01</t></is></c>'
            .'<c r="C113" t="inlineStr"><is><t>Nasteexo Sadak</t></is></c>'
            .'<c r="D113" t="inlineStr"><is><t>614503030</t></is></c>'
            .'<c r="H113" t="inlineStr"><is><t>15Maalin</t></is></c>'
            .'<c r="I113" t="inlineStr"><is><t>'.$remaining.'</t></is></c>'
            .'</row>';

        return $this->workbook($header.$row, $sheets);
    }

    /** @param  array<int, string>  $sheets */
    private function workbook(string $sheetData, array $sheets = ['Sheet1']): string
    {
        $path = tempnam(sys_get_temp_dir(), 'register').'.xlsx';

        $types = '';
        $tabs = '';
        $rels = '';

        foreach ($sheets as $index => $name) {
            $number = $index + 1;
            $types .= '<Override PartName="/xl/worksheets/sheet'.$number.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $tabs .= '<sheet name="'.$name.'" sheetId="'.$number.'" r:id="rId'.$number.'"/>';
            $rels .= '<Relationship Id="rId'.$number.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$number.'.xml"/>';
        }

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$types.'</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$tabs.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels.'</Relationships>');

        foreach ($sheets as $index => $name) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml',
                '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetData>'.($index === 0 ? $sheetData : '').'</sheetData></worksheet>');
        }

        $zip->close();

        return $path;
    }
}
