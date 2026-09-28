<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AlphaSchoolSyncService;
use Illuminate\Console\Command;

/**
 * Reads a newer copy of the school's register against the students on file.
 *
 * --dry-run reads the workbook and every student it names and prints, row by
 * row, what would change and what the screen would then show. It opens no
 * write. The real run wants --confirm, and it only ever updates, creates, or
 * leaves alone: a student missing from the newer book is a student the book is
 * silent about, never one to remove.
 */
class SyncAlphaSchool extends Command
{
    protected $signature = 'alpha-school:sync
        {--file= : Path to the .xlsx, default from config}
        {--sheet= : Which sheet holds the register, default from config}
        {--dry-run : Report what would change and change nothing}
        {--confirm : Actually write the changes}
        {--allow-reopen : Also apply rows that give days back to a completed student}
        {--details=0 : How many rows to print per table, 0 for all}
        {--changes-only : Print only the rows something would happen to}';

    protected $description = 'Sync students from a newer Alpha School register: update, create, never delete';

    public function handle(AlphaSchoolSyncService $sync): int
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

        $plan = $sync->plan($path, $this->option('sheet'), (bool) $this->option('allow-reopen'));

        $this->report($plan, $dryRun);

        if ($dryRun) {
            $this->newLine();
            $this->heading('DATABASE WRITES: 0 — DRY RUN ONLY');
            $this->line('Run the same command with --confirm to write it.');

            return self::SUCCESS;
        }

        $result = $sync->apply($plan, $this->actor());

        if ($result['failures'] !== []) {
            $this->newLine();
            $this->error(sprintf('%d rows could not be written:', count($result['failures'])));
            $this->table(['Row', 'Student', 'Reason'], array_map(fn ($f) => [
                $f['row'] ?? '', $f['name'] ?? '', mb_substr((string) ($f['reason'] ?? ''), 0, 60),
            ], array_slice($result['failures'], 0, 25)));
            $this->newLine();
            $this->error('SYNC FAILED — no part of this run should be assumed applied.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->heading('SYNC COMPLETE');
        $this->line(sprintf('NEW_STUDENTS:          %d', $result['created']));
        $this->line(sprintf('UPDATED_STUDENTS:      %d', $result['updated']));
        $this->line(sprintf('UNCHANGED_STUDENTS:    %d', $result['unchanged']));
        $this->line(sprintf('LEFT FOR REVIEW:       %d', $result['reviewed']));
        $this->line(sprintf('PAYMENT_CREATE:        %d', $result['payments_created']));
        $this->line(sprintf('PAYMENT_ALREADY_EXISTS:%d', $result['payments_existing']));

        foreach ($result['fields'] as $field => $count) {
            $this->line(sprintf('  %-26s %d', $field, $count));
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
        $flagged = fn (string $flag) => array_values(array_filter($rows, fn ($r) => in_array($flag, $r['flags'], true)));
        $paid = fn (string $decision) => array_values(array_filter($rows, fn ($r) => $r['payment'] === $decision));

        $create = $by(AlphaSchoolSyncService::CREATE);
        $update = $by(AlphaSchoolSyncService::UPDATE);
        $same = $by(AlphaSchoolSyncService::NO_CHANGE);
        $review = $by(AlphaSchoolSyncService::NEEDS_REVIEW);

        $this->newLine();
        $this->heading($dryRun ? 'ALPHA SCHOOL REGISTER SYNC — DRY RUN' : 'ALPHA SCHOOL REGISTER SYNC');

        $this->line(sprintf('Workbook:                %s', $plan['source']));
        $this->line(sprintf('  size / modified:       %s bytes, %s',
            number_format(filesize($plan['source'])), date('Y-m-d H:i:s', filemtime($plan['source']))));
        $this->line(sprintf('  SHA-256:               %s', hash_file('sha256', $plan['source'])));
        $this->line(sprintf('Sheet:                   %s', $plan['sheet']));
        $this->line(sprintf('Database:                %s', config('database.connections.'.config('database.default').'.database')));
        $this->newLine();
        $this->line(sprintf('Workbook rows read:      %d', $plan['rows_read']));
        $this->line(sprintf('Blank rows:              %d', $plan['blank']));
        $this->line(sprintf('Valid rows:              %d', count($create) + count($update) + count($same)));
        $this->line(sprintf('Rows needing review:     %d', count($review)));
        $this->newLine();
        $this->line(sprintf('NEW_STUDENTS:            %d', count($create)));
        $this->line(sprintf('UPDATED_STUDENTS:        %d', count($update)));
        $this->line(sprintf('UNCHANGED_STUDENTS:      %d', count($same)));
        $this->line(sprintf('NEEDS_REVIEW:            %d', count($review)));
        $this->newLine();
        $this->line(sprintf('COMPLETED_BY_NEW_FILE:   %d', count($flagged('COMPLETED_BY_NEW_FILE'))));
        $this->line(sprintf('REOPEN_REQUIRED:         %d', count($flagged('REOPEN_REQUIRED'))));
        $this->line(sprintf('PAYMENT_CREATE:          %d', count($paid(AlphaSchoolSyncService::PAYMENT_CREATE))));
        $this->line(sprintf('PAYMENT_ALREADY_EXISTS:  %d', count($paid(AlphaSchoolSyncService::PAYMENT_ALREADY_EXISTS))));
        $this->line(sprintf('PAYMENT_NEEDS_REVIEW:    %d', count($paid(AlphaSchoolSyncService::PAYMENT_NEEDS_REVIEW))));
        $this->line(sprintf('INVALID_PHONE:           %d', count($flagged('INVALID_PHONE'))));
        $this->line(sprintf('DUPLICATE_SOURCE_PHONE:  %d', count($flagged('DUPLICATE_SOURCE_PHONE'))));
        $this->line(sprintf('MALFORMED_DURATION:      %d', count($flagged('MALFORMED_DURATION'))));
        $this->line(sprintf('MALFORMED_REMAINING:     %d', count($flagged('MALFORMED_REMAINING'))));

        $this->issues('REOPEN_REQUIRED — A COMPLETED STUDENT IS GIVEN DAYS BACK', $flagged('REOPEN_REQUIRED'));
        $this->issues('NEEDS REVIEW — NOTHING IS WRITTEN FOR THESE', $review);
        $this->payments('PAYMENTS — THE REGISTER AND THE BOOKS DISAGREE', $paid(AlphaSchoolSyncService::PAYMENT_NEEDS_REVIEW));
        $this->creations($create);
        $this->fieldChanges($update);
        $this->detail($rows);
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
            $r['row'], mb_substr((string) $r['name'], 0, 26), $r['phone'] ?? '—',
            $r['reason'] ?? implode(', ', $r['flags']),
        ], $rows));
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    protected function payments(string $title, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->heading($title);
        $this->table(['Row', 'Student', 'Decision', 'Detail'], array_map(fn ($r) => [
            $r['row'], mb_substr((string) $r['name'], 0, 26), $r['payment'], $r['payment_detail'],
        ], $rows));
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    protected function creations(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->heading(sprintf('CREATE — %d STUDENTS THE SCHOOL DOES NOT HAVE', count($rows)));
        $this->table(['Row', 'Name', 'Phone', 'Start', 'Required', 'Remaining', 'Status', 'Payment'],
            array_map(fn ($r) => [
                $r['row'],
                mb_substr((string) $r['name'], 0, 26),
                $r['phone'],
                $r['record']['student']['start_date'] ?? '',
                $r['record']['student']['required_training_days'] ?? '',
                $r['record']['source']['remaining_days'] ?? '—',
                $r['record']['student']['status'] ?? '',
                $r['payment'],
            ], $this->limited($rows)));
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    protected function fieldChanges(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->heading(sprintf('UPDATE — %d STUDENTS, FIELD BY FIELD', count($rows)));

        foreach ($this->limited($rows) as $r) {
            $this->newLine();
            $this->line(sprintf('%s  (%s, db id %d, workbook row %d)',
                $r['name'], $r['phone'], $r['student_id'], $r['row']));

            foreach ($r['changes'] as $field => $value) {
                $this->line(sprintf('    %-24s %s → %s', $field,
                    var_export($r['before'][$this->label($field)] ?? null, true), var_export($value, true)));
            }

            if ($r['remaining'] !== null) {
                $this->line(sprintf('    %-24s %s → %s   (completed %s → %s, progress %s%% → %s%%)',
                    'Remaining', $r['before']['remaining'], $r['after']['remaining'],
                    $r['before']['completed'], $r['after']['completed'],
                    $r['before']['progress'], $r['after']['progress']));
            }

            if ($r['status'] !== null || $r['before']['status'] !== $r['after']['status']) {
                $this->line(sprintf('    %-24s %s → %s', 'Status', $r['before']['status'], $r['after']['status']));
            }

            if ($r['payment'] !== AlphaSchoolSyncService::PAYMENT_NONE) {
                $this->line(sprintf('    %-24s %s', 'Payment', $r['payment']));
            }
        }
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    protected function detail(array $rows): void
    {
        if ($this->option('changes-only')) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['decision'] !== AlphaSchoolSyncService::NO_CHANGE));
        }

        $this->newLine();
        $this->heading(sprintf('EVERY REGISTER ROW — %d OF THEM', count($rows)));

        $this->table(['Row', 'Student', 'Phone', 'Req', 'Rem be', 'Rem af', 'Status be', 'Status af', 'Payment', 'Decision'],
            array_map(fn ($r) => [
                $r['row'],
                mb_substr((string) $r['name'], 0, 22),
                $r['phone'] ?? '—',
                $r['before']['required'] ?? ($r['record']['student']['required_training_days'] ?? '—'),
                $r['before']['remaining'] ?? '—',
                $r['after']['remaining'] ?? ($r['before']['remaining'] ?? '—'),
                $r['before']['status'] ?? ($r['record']['student']['status'] ?? '—'),
                $r['after']['status'] ?? ($r['before']['status'] ?? '—'),
                $r['payment'],
                $r['decision'],
            ], $this->limited($rows)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function limited(array $rows): array
    {
        $limit = (int) $this->option('details');

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    protected function label(string $field): string
    {
        return $field === 'required_training_days' ? 'required' : $field;
    }

    protected function actor(): ?User
    {
        return User::whereHas('role', fn ($q) => $q->where('name', Role::ADMIN))->orderBy('id')->first();
    }

    protected function heading(string $title): void
    {
        $this->line(str_repeat('=', 78));
        $this->line($title);
        $this->line(str_repeat('=', 78));
    }
}
