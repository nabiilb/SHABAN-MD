<?php

namespace App\Console\Commands;

use App\Services\AlphaSchoolImporter;
use App\Support\XlsxReader;
use Illuminate\Console\Command;

/**
 * Answers the question "which file is the application actually reading, and
 * what does it see in it?"
 *
 * When a column reads as empty and the person looking at the same spreadsheet
 * can plainly see numbers in it, one of a small number of things is true: the
 * file on the server is not the file on the desk, the sheet being read is not
 * the sheet being looked at, or the cells hold something other than what they
 * display. This prints enough to tell which, and writes nothing at all.
 */
class DiagnoseAlphaWorkbook extends Command
{
    protected $signature = 'alpha-school:diagnose-workbook
        {--file= : Path to the .xlsx, default from config}
        {--sheet= : Which sheet to read, default from config}
        {--row=113 : A register row to inspect cell by cell}
        {--student=Nasteexo Sadak : A name to look up and parse}
        {--samples=8 : How many example values to print per heading}';

    protected $description = 'Report which workbook is being read and what the importer sees in it';

    /** Column H is the course length, column I the days left or a completion word. */
    private const DURATION = 'H';

    private const REMAINING = 'I';

    public function handle(AlphaSchoolImporter $importer): int
    {
        $path = $this->option('file') ?: config('alpha_school_import.file');

        $this->heading('1. THE FILE');

        $this->line(sprintf('Configured path:  %s', $path));
        $this->line(sprintf('Resolved path:    %s', realpath($path) ?: '(does not resolve)'));
        $this->line(sprintf('Exists:           %s', is_file($path) ? 'yes' : 'NO'));
        $this->line(sprintf('Readable:         %s', is_readable($path) ? 'yes' : 'NO'));

        if (! is_readable($path)) {
            $this->newLine();
            $this->error('There is no readable workbook at that path, so nothing below can be reported.');

            return self::FAILURE;
        }

        clearstatcache(true, $path);

        $this->line(sprintf('Size:             %s bytes', number_format(filesize($path))));
        $this->line(sprintf('Modified:         %s', date('Y-m-d H:i:s', filemtime($path))));
        $this->line(sprintf('SHA-256:          %s', hash_file('sha256', $path)));
        $this->line(sprintf('MD5:              %s', md5_file($path)));

        // Anything else with the same name elsewhere on the box is worth
        // knowing about: an upload that landed beside the real one rather
        // than on top of it looks exactly like an import that ignored it.
        $this->siblings($path);

        $sheet = $this->sheets($path);

        $this->cells($path, $sheet);
        $this->student($importer, $path, $sheet);
        $this->remainingColumn($path, $sheet);

        $this->newLine();
        $this->heading('NOTHING WAS WRITTEN — THIS COMMAND ONLY READS');

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */

    protected function siblings(string $path): void
    {
        $others = array_filter(
            glob(dirname($path).'/*.xlsx') ?: [],
            fn ($file) => realpath($file) !== realpath($path),
        );

        if ($others === []) {
            return;
        }

        $this->newLine();
        $this->warn('Other workbooks in the same directory:');
        $this->table(['File', 'Size', 'Modified'], array_map(fn ($file) => [
            basename($file), number_format(filesize($file)), date('Y-m-d H:i:s', filemtime($file)),
        ], $others));
    }

    protected function sheets(string $path): string
    {
        $names = XlsxReader::sheetNames($path);
        $configured = $this->option('sheet') ?: config('alpha_school_import.sheet', 'Sheet1');
        $selected = in_array($configured, $names, true) ? $configured : ($names[0] ?? 'Sheet1');

        $this->newLine();
        $this->heading('2. THE SHEETS');
        $this->table(['#', 'Sheet name', 'Read?'], array_map(
            fn ($name, $index) => [$index + 1, $name, $name === $selected ? '  <-- THIS ONE' : ''],
            $names, array_keys($names),
        ));

        if ($selected !== $configured) {
            $this->error(sprintf('The configured sheet "%s" is not in this workbook; falling back to "%s".', $configured, $selected));
        }

        return $selected;
    }

    protected function cells(string $path, string $sheet): void
    {
        $row = (int) $this->option('row');

        $this->newLine();
        $this->heading(sprintf('3. ROW %d, CELL BY CELL', $row));

        $rows = [];

        foreach (['C', self::DURATION, self::REMAINING] as $column) {
            $cell = XlsxReader::describeCell($path, $sheet, $column.$row);

            $rows[] = [
                $cell['reference'] ?: $column.$row,
                $cell['found'] ? 'yes' : 'no cell at all',
                $cell['type'],
                $cell['style'],
                $cell['formula'] === null ? '—' : $cell['formula'],
                $cell['raw'] === null ? '(no <v>)' : $cell['raw'],
                $cell['value'] === null ? '(read as blank)' : (string) $cell['value'],
            ];
        }

        $this->table(['Cell', 'Present', 'Type', 'Style', 'Formula', 'Stored', 'Read as'], $rows);

        $remaining = XlsxReader::describeCell($path, $sheet, self::REMAINING.$row);

        if ($remaining['formula'] !== null && $remaining['raw'] === null) {
            $this->error('That cell holds a formula whose result was never saved with it.');
            $this->line('Open the workbook in Excel and save it again, so the value is stored beside the formula.');
        }

        if ($remaining['is_date_style']) {
            $this->warn('That cell is formatted as a date, so a number typed into it is read as one.');
        }
    }

    protected function student(AlphaSchoolImporter $importer, string $path, string $sheet): void
    {
        $name = (string) $this->option('student');

        $this->newLine();
        $this->heading(sprintf('4. "%s", AS THE IMPORTER READS THE ROW', $name));

        $plan = $importer->parse($path, $sheet);

        $matches = array_values(array_filter(
            $plan['records'],
            fn ($record) => stripos((string) ($record['student']['full_name'] ?? ''), $name) !== false,
        ));

        if ($matches === []) {
            $this->error(sprintf('No row in this workbook has a name containing "%s".', $name));

            return;
        }

        $this->table(
            ['Row', 'Name', 'Phone', 'H raw', 'I raw', 'Required', 'Remaining', 'Status'],
            array_map(fn ($record) => [
                $record['row'],
                mb_substr($record['student']['full_name'], 0, 26),
                $record['student']['phone'],
                $record['source']['duration_raw'] ?? '(blank)',
                $record['source']['remaining_raw'] ?? '(blank)',
                $record['student']['required_training_days'],
                $record['source']['remaining_days'] === null ? '(none)' : $record['source']['remaining_days'],
                $record['student']['status'],
            ], $matches),
        );
    }

    protected function remainingColumn(string $path, string $sheet): void
    {
        $rows = XlsxReader::rows($path, $sheet);
        $samples = max(1, (int) $this->option('samples'));

        $buckets = ['whole number' => [], 'completion word' => [], 'blank' => [], 'malformed' => []];

        foreach ($rows as $number => $cells) {
            if ($number < 2 || trim((string) ($cells['C'] ?? '')) === '') {
                continue;
            }

            $raw = trim((string) ($cells[self::REMAINING] ?? ''));
            $letters = preg_replace('/[^a-z]/', '', mb_strtolower($raw));

            $bucket = match (true) {
                $raw === '' => 'blank',
                in_array($letters, ['complate', 'complete', 'completed', 'compleated', 'dhameystiray'], true) => 'completion word',
                preg_match('/^\d+$/', $raw) === 1 => 'whole number',
                default => 'malformed',
            };

            $buckets[$bucket][] = ['row' => $number, 'value' => $raw];
        }

        $this->newLine();
        $this->heading('5. COLUMN '.self::REMAINING.' ACROSS THE WHOLE SHEET');

        $this->table(['Reads as', 'Rows', 'Examples'], array_map(fn ($label, $found) => [
            $label,
            count($found),
            implode(', ', array_map(
                fn ($item) => 'r'.$item['row'].'="'.$item['value'].'"',
                array_slice($found, 0, $samples),
            )) ?: '—',
        ], array_keys($buckets), $buckets));

        $numbers = count($buckets['whole number']);

        $this->newLine();

        if ($numbers > 0) {
            $this->info(sprintf('%d rows carry a number of days. The repair has balances to apply.', $numbers));

            return;
        }

        $this->error('Not one row in column '.self::REMAINING.' of this sheet holds a number.');
        $this->line('The file being read is not the corrected one, or the corrections are on another sheet.');
        $this->line('Compare the SHA-256 above with the file you edited.');
    }

    protected function heading(string $title): void
    {
        $this->line(str_repeat('=', 78));
        $this->line($title);
        $this->line(str_repeat('=', 78));
    }
}
