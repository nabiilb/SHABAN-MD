<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AlphaSchoolImporter;
use Illuminate\Console\Command;

/**
 * Imports the school's register.
 *
 * --dry-run reads the whole file and prints exactly what would happen without
 * opening a single write. It is the intended first run: the register is kept
 * by hand, and the report is how you find the rows that need a pen before the
 * import touches anything.
 */
class ImportAlphaSchool extends Command
{
    protected $signature = 'alpha-school:import
        {--file= : Path to the .xlsx, default storage/app/imports/ALPHA SCHOOL.xlsx}
        {--sheet= : Which sheet holds the register, default from config}
        {--dry-run : Report what would happen and change nothing}
        {--details=15 : How many rows to list under each heading}';

    protected $description = 'Import students and payments from the Alpha School Excel register';

    public function handle(AlphaSchoolImporter $importer): int
    {
        $path = $this->option('file') ?: config('alpha_school_import.file');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");
            $this->line('Put the workbook at storage/app/imports/ALPHA SCHOOL.xlsx, or pass --file=');

            return self::FAILURE;
        }

        $this->line('Reading '.$path);

        $plan = $importer->parse($path, $this->option('sheet') ?: config('alpha_school_import.sheet', 'Sheet1'));

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('details'));

        $create = array_filter($plan['records'], fn ($r) => $r['action'] === 'create');
        $update = array_filter($plan['records'], fn ($r) => $r['action'] === 'update');
        $skip = array_filter($plan['records'], fn ($r) => $r['action'] === 'skip');
        $payments = array_filter($plan['records'], fn ($r) => $r['action'] !== 'skip' && $r['payment']);

        $this->heading($dryRun ? 'ALPHA SCHOOL IMPORT DRY RUN' : 'ALPHA SCHOOL IMPORT');

        $this->line(sprintf('Rows read:          %d', $plan['rows_read']));
        $this->line(sprintf('Blank rows:         %d', $plan['blank']));
        $this->line(sprintf('Valid:              %d', count($create) + count($update)));
        $this->line(sprintf('New students:       %d', count($create)));
        $this->line(sprintf('Existing students:  %d', count($update)));
        $this->line(sprintf('Skipped:            %d', count($skip)));
        $this->line(sprintf('Payments to create: %d', count($payments)));

        $this->rejections($skip, $limit);
        $this->notices($plan['notices'], $limit);

        if ($dryRun) {
            $this->newLine();
            $this->heading('NO DATABASE CHANGES WERE MADE');
            $this->line('Run the same command without --dry-run to apply it.');

            return self::SUCCESS;
        }

        if (! $this->option('no-interaction') && ! $this->confirm(sprintf(
            'Create %d students and %d payments?', count($create), count($payments),
        ), true)) {
            $this->warn('Nothing was imported.');

            return self::SUCCESS;
        }

        $result = $importer->apply($plan, $this->actor());

        $this->newLine();
        $this->heading('IMPORT COMPLETE');
        $this->line(sprintf('Students created:   %d', $result['created']));
        $this->line(sprintf('Students updated:   %d', $result['updated']));
        $this->line(sprintf('Students skipped:   %d', $result['skipped']));
        $this->line(sprintf('Payments created:   %d', $result['payments']));
        $this->line(sprintf('Payments already there: %d', $result['payments_existing']));

        if ($result['failures'] !== []) {
            $this->newLine();
            $this->error(sprintf('%d rows could not be written:', count($result['failures'])));
            $this->table(['Row', 'Name', 'Reason'], array_map(
                fn ($f) => [$f['row'], $f['name'], mb_substr($f['reason'], 0, 80)],
                array_slice($result['failures'], 0, $limit),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** The admin the imported records are attributed to, when there is one. */
    protected function actor(): ?User
    {
        return User::whereHas('role', fn ($q) => $q->where('name', Role::ADMIN))
            ->orderBy('id')
            ->first();
    }

    protected function rejections(array $skipped, int $limit): void
    {
        if ($skipped === []) {
            return;
        }

        $this->newLine();
        $this->warn(sprintf('Skipped rows (%d):', count($skipped)));
        $this->table(['Row', 'Name', 'Phone', 'Reason'], array_map(fn ($r) => [
            $r['row'],
            $r['student']['full_name'] ?? '',
            $r['student']['phone'] ?? '',
            $r['reason'],
        ], array_slice($skipped, 0, $limit)));

        if (count($skipped) > $limit) {
            $this->line(sprintf('  … and %d more. Pass --details=%d to see them all.', count($skipped) - $limit, count($skipped)));
        }
    }

    protected function notices(array $notices, int $limit): void
    {
        $headings = [
            'dates_read_day_first' => 'Dates Excel had stored month-first, read as day-first to match the column',
            'dates_carried_forward' => 'Rows with no date, given the date of the row above',
            'amounts_not_numeric' => 'Amounts that were not plain numbers (the figure was taken, the words kept in notes)',
            'durations_not_understood' => 'Durations left at the school default (the words are kept in notes)',
            'column_one_not_understood' => 'Column1 was neither "complate" nor a number of days (left active, no opening balance)',
            'duplicates_in_file' => 'Rows sharing a phone number with an earlier row',
            'years_out_of_step' => 'Dates whose year is not the register\'s year — check these for a slip of the pen',
            'dates_corrected_by_config' => 'Dates corrected by config/alpha_school_import.php',
            'rows_skipped_by_config' => 'Rows excluded by config/alpha_school_import.php',
        ];

        foreach ($headings as $key => $heading) {
            $rows = $notices[$key] ?? [];

            if ($rows === []) {
                continue;
            }

            $this->newLine();
            $this->warn(sprintf('%s (%d):', $heading, count($rows)));

            $this->table(array_map('ucfirst', array_keys($rows[0])), array_map(
                fn ($row) => array_values(array_map(fn ($v) => (string) $v, $row)),
                array_slice($rows, 0, $limit),
            ));

            if (count($rows) > $limit) {
                $this->line(sprintf('  … and %d more.', count($rows) - $limit));
            }
        }
    }

    protected function heading(string $title): void
    {
        $this->line(str_repeat('=', 40));
        $this->line($title);
        $this->line(str_repeat('=', 40));
    }
}
