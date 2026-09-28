<?php

namespace App\Console\Commands;

use App\Exceptions\RepairNotPersisted;
use App\Services\AlphaTrainingProgressRepair;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrects the course length and the days still to run on students the Alpha
 * register already brought in.
 *
 * --dry-run reads the register and every student it names and prints, row by
 * row, what it would change and what the screen would then show. It opens no
 * write. The real run wants --confirm, and it only ever touches the four
 * columns this correction is about.
 */
class RepairAlphaTrainingProgress extends Command
{
    protected $signature = 'alpha-school:repair-training-progress
        {--file= : Path to the .xlsx, default from config}
        {--sheet= : Which sheet holds the register, default from config}
        {--dry-run : Report what would change and change nothing}
        {--confirm : Actually write the corrections}
        {--details=0 : How many rows to print per table, 0 for all}
        {--changes-only : Print only the rows something would happen to}';

    protected $description = 'Repair required and remaining training days for students imported from the Alpha register';

    public function handle(AlphaTrainingProgressRepair $repair): int
    {
        $path = $this->option('file') ?: config('alpha_school_import.file');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $confirmed = (bool) $this->option('confirm');

        if ($dryRun && $confirmed) {
            $this->error('Pass either --dry-run or --confirm, not both.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $confirmed) {
            $this->error('Refusing to guess. Pass --dry-run to see the plan, or --confirm to write it.');

            return self::FAILURE;
        }

        $this->line('Reading '.$path);

        $plan = $repair->plan($path, $this->option('sheet'));

        $this->report($plan, $dryRun);

        if ($dryRun) {
            $this->newLine();
            $this->heading('DRY RUN — NO DATABASE CHANGES WERE MADE');
            $this->line('Run the same command with --confirm to write it.');

            return self::SUCCESS;
        }

        $writes = array_values(array_filter($plan['rows'], fn ($r) => $r['changes'] !== []));

        if ($writes === []) {
            $this->newLine();
            $this->warn('Every student already matches the register. Nothing to do.');

            return self::SUCCESS;
        }

        // --confirm is the confirmation. Nothing is asked out loud.
        //
        // There was a second question here. It cost nothing at a terminal and
        // everything anywhere else: under php-fpm, mod_php or the built-in
        // server — a deploy page in public_html reaching this through
        // $kernel->call() — PHP defines no STDIN, Symfony's question helper
        // reaches for it regardless, and the request dies on
        // `Undefined constant "STDIN"` part way through, with no output, no
        // exit code and nothing written. Passing --no-interaction only traded
        // that for a quieter wrong answer: an unanswerable question falls back
        // to its default, which was "no", so the run reported success and
        // changed nothing at all.
        //
        // A flag nobody types by accident is confirmation enough, and it means
        // the same thing from a terminal, a cron line and a web page.
        $this->newLine();
        $this->line(sprintf(
            'Correcting %d students. Only %s are written.',
            count($writes), implode(', ', AlphaTrainingProgressRepair::WRITES),
        ));

        try {
            $result = $repair->apply($plan);
        } catch (RepairNotPersisted $e) {
            // The writes reported no error and the database does not hold
            // them. Saying so is the whole job here: a run that cannot prove
            // it applied must never read as one that did.
            $this->newLine();
            $this->error('THE REPAIR DID NOT PERSIST.');
            $this->line($e->getMessage());
            $this->newLine();
            $this->table(['Student id', 'Column', 'Written', 'Found on file'], array_map(fn ($r) => [
                $r['id'], $r['column'],
                var_export($r['expected'], true),
                var_export($r['actual'], true),
            ], array_slice($e->rows, 0, max(1, (int) $this->option('details') ?: 25))));

            if (count($e->rows) > 25) {
                $this->line(sprintf('  … and %d more.', count($e->rows) - 25));
            }

            $this->newLine();
            $this->warn('Nothing here can be treated as applied. Run the dry run again before doing anything else.');

            return self::FAILURE;
        }

        // Failures first: a run with any of them has not finished, whatever
        // else it managed, and must not be headed COMPLETE.
        if ($result['failures'] !== []) {
            $this->newLine();
            $this->error(sprintf('%d students could not be written:', count($result['failures'])));
            $this->table(['Row', 'Student', 'Reason'], array_map(fn ($f) => [
                $f['row'], $f['name'], mb_substr($f['reason'], 0, 60),
            ], array_slice($result['failures'], 0, 25)));

            if (count($result['failures']) > 25) {
                $this->line(sprintf('  … and %d more.', count($result['failures']) - 25));
            }

            $this->newLine();
            $this->error('REPAIR FAILED — see above. No part of this run should be assumed applied.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->heading('REPAIR COMPLETE');
        $this->line(sprintf('Students corrected:   %d', $result['updated']));
        $this->line(sprintf('  read back and confirmed on file: %d', $result['verified']));

        // Committed inside somebody else's transaction, the rows read back
        // correctly and can still be thrown away by whoever owns it. Worth
        // saying out loud rather than refusing over, because a test suite
        // legitimately runs this way.
        if (DB::transactionLevel() > 0) {
            $this->warn(sprintf(
                '  Note: this ran inside an open transaction (depth %d); another caller decides whether it is kept.',
                DB::transactionLevel(),
            ));
        }

        $this->line(sprintf('Already correct:      %d', $result['unchanged']));
        $this->line(sprintf('Left alone:           %d', $result['skipped']));

        foreach ($result['fields'] as $field => $count) {
            $this->line(sprintf('  %-24s %d', $field, $count));
        }

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------
       The report
       ------------------------------------------------------------------ */

    /** @param  array<string, mixed>  $plan */
    protected function report(array $plan, bool $dryRun): void
    {
        $rows = $plan['rows'];
        $by = fn (string $decision) => array_values(array_filter($rows, fn ($r) => $r['decision'] === $decision));

        $matched = array_values(array_filter($rows, fn ($r) => $r['student_id'] !== null));
        $completedWord = array_values(array_filter($rows, fn ($r) => $this->remainingIsCompletionWord($r)));
        $numeric = array_values(array_filter($rows, fn ($r) => $this->remainingIsNumber($r)));
        $blank = array_values(array_filter($rows, fn ($r) => $r['remaining_raw'] === null));
        $malformed = array_values(array_filter($rows, fn ($r) => $r['remaining_raw'] !== null
            && ! $this->remainingIsNumber($r) && ! $this->remainingIsCompletionWord($r)));

        $requiredDiffers = array_values(array_filter($matched, fn ($r) => isset($r['changes']['required_training_days'])));
        $remainingDiffers = array_values(array_filter($matched, fn ($r) => isset($r['changes']['opening_remaining_days'])));
        $statusChanges = array_values(array_filter($matched, fn ($r) => isset($r['changes']['status'])));

        $this->newLine();
        $this->heading($dryRun ? 'ALPHA REQUIRED vs REMAINING REPAIR — DRY RUN' : 'ALPHA REQUIRED vs REMAINING REPAIR');

        // Which file this actually was. A report that does not say cannot
        // be told apart from the same report over yesterday's copy.
        $this->line(sprintf('Workbook:                           %s', $plan['source'] ?? '(unknown)'));

        if (isset($plan['source']) && is_readable($plan['source'])) {
            $this->line(sprintf('  size / modified:                  %s bytes, %s',
                number_format(filesize($plan['source'])), date('Y-m-d H:i:s', filemtime($plan['source']))));
            $this->line(sprintf('  SHA-256:                          %s', hash_file('sha256', $plan['source'])));
        }

        $this->line(sprintf('Sheet:                              %s', $plan['sheet'] ?? '(default)'));
        $this->newLine();
        $this->line(sprintf('Rows read from the register:        %d', $plan['rows_read']));
        $this->line(sprintf('Blank rows:                         %d', $plan['blank']));
        $this->line(sprintf('Valid source students:              %d', count($rows)));
        $this->line(sprintf('Matched in the database:            %d', count($matched)));
        $this->line(sprintf('Not matched:                        %d', count($by(AlphaTrainingProgressRepair::NOT_MATCHED))));
        $this->newLine();
        $this->line(sprintf('Remaining column — completion word: %d', count($completedWord)));
        $this->line(sprintf('Remaining column — whole number:    %d', count($numeric)));
        $this->line(sprintf('Remaining column — blank:           %d', count($blank)));
        $this->line(sprintf('Remaining column — malformed:       %d', count($malformed)));
        $this->newLine();
        $this->line(sprintf('Required differs from register:     %d', count($requiredDiffers)));
        $this->line(sprintf('Remaining differs from register:    %d', count($remainingDiffers)));
        $this->line(sprintf('Status would change:                %d', count($statusChanges)));
        $this->line(sprintf('No change needed:                   %d', count($by(AlphaTrainingProgressRepair::NO_CHANGE))));
        $this->line(sprintf('Needing review, nothing written:    %d', count($by(AlphaTrainingProgressRepair::NEEDS_REVIEW))));

        $this->decisions($rows);
        $this->issues('NEEDING REVIEW — NOTHING IS WRITTEN FOR THESE', $by(AlphaTrainingProgressRepair::NEEDS_REVIEW));
        $this->issues('NOT MATCHED — NO STUDENT ON FILE WITH THIS PHONE', $by(AlphaTrainingProgressRepair::NOT_MATCHED));
        $this->detail($rows);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    protected function decisions(array $rows): void
    {
        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['decision']] = ($counts[$row['decision']] ?? 0) + 1;
        }

        arsort($counts);

        $this->newLine();
        $this->heading('DECISIONS');
        $this->table(['Decision', 'Rows'], array_map(
            fn ($k, $v) => [$k, $v], array_keys($counts), $counts,
        ));
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    protected function issues(string $title, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->heading($title);
        $this->table(['Row', 'Student', 'Phone', 'Why'], array_map(fn ($r) => [
            $r['row'], mb_substr($r['name'], 0, 28), $r['phone'], $r['reason'],
        ], $rows));
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    protected function detail(array $rows): void
    {
        if ($this->option('changes-only')) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['changes'] !== []
                || $r['decision'] === AlphaTrainingProgressRepair::NEEDS_REVIEW
                || $r['decision'] === AlphaTrainingProgressRepair::NOT_MATCHED));
        }

        $limit = (int) $this->option('details');
        $shown = $limit > 0 ? array_slice($rows, 0, $limit) : $rows;

        $this->newLine();
        $this->heading(sprintf('EVERY REGISTER ROW — %d OF THEM', count($rows)));

        $this->table([
            'Row', 'Student', 'Phone', 'Dur raw', 'Req be', 'Req af', 'Rem raw',
            'Rem be', 'Rem af', 'Cmp be', 'Cmp af', 'Prog be', 'Prog af',
            'Status be', 'Status af', 'Decision',
        ], array_map(fn ($r) => [
            $r['row'],
            mb_substr($r['name'], 0, 22),
            $r['phone'],
            mb_substr((string) $r['duration_raw'], 0, 10),
            $r['before']['required'] ?? '—',
            $this->after($r, 'required'),
            $r['remaining_raw'] === null ? '(blank)' : mb_substr($r['remaining_raw'], 0, 10),
            $r['before']['remaining'] ?? '—',
            $this->after($r, 'remaining'),
            $r['before']['completed'] ?? '—',
            $this->after($r, 'completed'),
            isset($r['before']) ? $r['before']['progress'].'%' : '—',
            isset($r['after']) ? $this->after($r, 'progress').'%' : '—',
            $r['before']['status'] ?? '—',
            $this->after($r, 'status'),
            $r['decision'],
        ], $shown));

        if (count($shown) < count($rows)) {
            $this->line(sprintf('  … and %d more. Pass --details=0 to print them all.', count($rows) - count($shown)));
        }
    }

    /**
     * The figure after the repair, or a dash where the row is not written.
     *
     * @param  array<string, mixed>  $row
     */
    protected function after(array $row, string $key): string
    {
        if ($row['after'] === null) {
            return '—';
        }

        $before = (string) ($row['before'][$key] ?? '');
        $after = (string) ($row['after'][$key] ?? '');

        return $before === $after ? $after : $after.' *';
    }

    /** @param  array<string, mixed>  $row */
    protected function remainingIsNumber(array $row): bool
    {
        return $row['remaining_raw'] !== null && preg_match('/^\d+$/', $row['remaining_raw']) === 1;
    }

    /** @param  array<string, mixed>  $row */
    protected function remainingIsCompletionWord(array $row): bool
    {
        if ($row['remaining_raw'] === null) {
            return false;
        }

        $letters = preg_replace('/[^a-z]/', '', mb_strtolower($row['remaining_raw']));

        return in_array($letters, ['complate', 'complete', 'completed', 'compleated', 'dhameystiray'], true);
    }

    protected function heading(string $title): void
    {
        $this->line(str_repeat('=', 78));
        $this->line($title);
        $this->line(str_repeat('=', 78));
    }
}
