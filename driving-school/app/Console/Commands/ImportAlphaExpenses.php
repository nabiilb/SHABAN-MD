<?php

namespace App\Console\Commands;

use App\Exceptions\ImportNotPersisted;
use App\Models\Role;
use App\Models\User;
use App\Services\AlphaExpenseImporter;
use Illuminate\Console\Command;

/**
 * Imports the school's handwritten Word expense ledger.
 *
 * --dry-run reads the whole document and prints, line by line, exactly what
 * would happen without opening a single write. It is the intended first run,
 * and the only one that needs no confirmation: the ledger was kept by hand,
 * and the report is how the lines that need a pen are found before the import
 * touches anything.
 */
class ImportAlphaExpenses extends Command
{
    protected $signature = 'alpha:import-expenses
        {file? : Path to the .docx, default storage/app/imports/DEYNTA BISHI....docx}
        {--dry-run : Report what would happen and change nothing}
        {--confirm : Actually write the expenses}
        {--undated-date= : The date given to lines the document does not date (YYYY-MM-DD)}
        {--preview=0 : How many preview lines to print, 0 for all of them}';

    protected $description = 'Import the historical company expenses from the Alpha Driving School Word ledger';

    public function handle(AlphaExpenseImporter $importer): int
    {
        $path = $this->argument('file') ?: config('alpha_expense_import.file');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");
            $this->line('Put the ledger at storage/app/imports/, or pass the path as the first argument.');

            return self::FAILURE;
        }

        // Neither flag, or both: say so rather than guess which was meant.
        // The costly mistake here is a write nobody asked for.
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

        $plan = $importer->reconcile($importer->parse($path, [
            'undated_date' => $this->option('undated-date'),
        ]));

        $this->report($plan, $dryRun);

        if ($dryRun) {
            $this->newLine();
            $this->heading('DRY RUN — NO DATABASE CHANGES WERE MADE');
            $this->line('Run the same command with --confirm to write it.');

            return self::SUCCESS;
        }

        $toCreate = $this->decided($plan, AlphaExpenseImporter::IMPORT_EXPENSE);

        // A second run has nothing to create, and asking to write nothing only
        // invites a yes that means nothing.
        if ($toCreate === []) {
            $this->newLine();
            $this->warn($this->decided($plan, AlphaExpenseImporter::DUPLICATE) === []
                ? 'There is nothing in this document to import.'
                : 'Every expense in this document is already on file. Nothing to do.');

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
            'Creating %d expenses totalling %s.',
            count($toCreate), $this->money($this->sum($toCreate)),
        ));

        try {
            $result = $importer->apply($plan, $this->actor());
        } catch (ImportNotPersisted $e) {
            $this->newLine();
            $this->error('THE IMPORT DID NOT PERSIST.');
            $this->line($e->getMessage());
            $this->newLine();
            $this->table(['Expense id', 'Field', 'Written', 'Found on file'], array_map(fn ($r) => [
                $r['id'], $r['field'], (string) $r['expected'], (string) $r['actual'],
            ], array_slice($e->rows, 0, 25)));
            $this->newLine();
            $this->warn('Nothing here can be treated as imported. Run the dry run again before doing anything else.');

            return self::FAILURE;
        }

        // Failures before the banner: a run with any of them has not finished.
        if ($result['failures'] !== []) {
            $this->newLine();
            $this->error(sprintf('%d lines could not be written:', count($result['failures'])));
            $this->table(['Reference', 'Description', 'Reason'], array_map(fn ($f) => [
                $f['reference'], $f['description'], mb_substr($f['reason'], 0, 70),
            ], array_slice($result['failures'], 0, 25)));
            $this->newLine();
            $this->error('IMPORT FAILED — no part of this run should be assumed applied.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->heading('IMPORT COMPLETE');
        $this->line(sprintf('Expenses created:      %d', $result['created']));
        $this->line(sprintf('  read back and confirmed on file: %d', $result['verified']));
        $this->line(sprintf('Total written:         %s', $this->money($result['total'])));
        $this->line(sprintf('Already on file:       %d', $result['already']));
        $this->line(sprintf('Lines not imported:    %d', $result['skipped']));

        if ($result['categories_created'] !== []) {
            $this->line(sprintf('Categories created:    %s', implode(', ', $result['categories_created'])));
        }

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------
       The report
       ------------------------------------------------------------------ */

    /** @param  array<string, mixed>  $plan */
    protected function report(array $plan, bool $dryRun): void
    {
        $import = $this->decided($plan, AlphaExpenseImporter::IMPORT_EXPENSE);
        $fuelRecords = $this->decided($plan, AlphaExpenseImporter::IMPORT_FUEL);
        $already = $this->decided($plan, AlphaExpenseImporter::DUPLICATE);
        $totals = $this->decided($plan, AlphaExpenseImporter::SKIP_TOTAL);
        $headings = $this->decided($plan, AlphaExpenseImporter::SKIP_HEADING);
        $invalid = $this->decided($plan, AlphaExpenseImporter::INVALID);
        $income = $this->decided($plan, AlphaExpenseImporter::SKIP_INCOME);
        $review = $this->decided($plan, AlphaExpenseImporter::NEEDS_REVIEW);

        $dated = array_filter($import, fn ($e) => $e['date_source'] === 'line');

        $this->newLine();
        $this->heading($dryRun ? 'ALPHA EXPENSE IMPORT — DRY RUN' : 'ALPHA EXPENSE IMPORT');

        $this->line(sprintf('Source file:                   %s', $plan['source']));
        $this->line(sprintf('  size / modified:             %s bytes, %s',
            number_format(filesize($plan['source'])), date('Y-m-d H:i:s', filemtime($plan['source']))));
        $this->line(sprintf('  SHA-256:                     %s', hash_file('sha256', $plan['source'])));
        $this->line(sprintf('Database:                      %s', config('database.connections.'.config('database.default').'.database')));
        $this->newLine();
        $this->line(sprintf('Table rows scanned:            %d', $plan['rows_read']));
        $this->line(sprintf('Entries scanned:               %d', count($plan['entries'])));
        $this->line(sprintf('Blank pairs:                   %d', $plan['blank']));
        $this->newLine();
        $this->line(sprintf('IMPORT_EXPENSE:                %d  (%s)', count($import), $this->money($this->sum($import))));
        $this->line(sprintf('DUPLICATE, already on file:    %d  (%s)', count($already), $this->money($this->sum($already))));
        $this->line(sprintf('SKIP_INCOME:                   %d  (%s)', count($income), $this->money($this->sum($income))));
        $this->line(sprintf('SKIP_TOTAL:                    %d  (%s)', count($totals), $this->money($this->sum($totals))));
        $this->line(sprintf('SKIP_HEADING:                  %d', count($headings)));
        $this->line(sprintf('INVALID:                       %d', count($invalid)));
        $this->line(sprintf('NEEDS_REVIEW:                  %d', count($review)));

        if ($fuelRecords !== []) {
            $this->line(sprintf('Fuel records:                  %d', count($fuelRecords)));
        }

        $this->newLine();
        $this->line(sprintf('Dated by the document:         %d of %d safe rows', count($dated), count($import)));
        $this->line(sprintf('Undated, given %s:     %d', $plan['undated_date']->toDateString(), count($import) - count($dated)));

        $this->categoryTotals($import, $already);
        $this->missingCategories($plan);
        $this->fuelNote();
        $this->reconciliation($import, array_merge($income, $review), $totals);
        $this->exclusions('SKIP_INCOME — MONEY COMING IN, NOT SPENDING', $income);
        $this->exclusions('NEEDS_REVIEW — NOT IMPORTED', $review);
        $this->exclusions('SKIP_TOTAL — SUMMARY LINES', $totals);
        $this->exclusions('SKIP_HEADING — SECTION LABELS', $headings);
        $this->exclusions('INVALID — NO USABLE AMOUNT', $invalid);
        $this->dateNote($plan, $dated);
        $this->preview($plan);
    }

    /** Why fuel is a company expense here and not a fuel record. */
    protected function fuelNote(): void
    {
        $this->newLine();
        $this->heading('FUEL');
        $this->line('Fuel is imported as a CompanyExpense in the fuel category, not as a FuelRecord.');
        $this->line('  fuel_records requires a vehicle, a number of litres and a price per litre.');
        $this->line('  None of the three is nullable, and the ledger writes none of them down.');
        $this->line('  FuelService posts a CompanyExpense when a fuel record is approved, and the');
        $this->line('  dashboard totals expenses through company_expenses — so a fuel line belongs');
        $this->line('  there, and creating both rows would count the same tank twice.');
    }

    /**
     * What the document totals say, against what was read.
     *
     * @param  array<int, array<string, mixed>>  $import
     * @param  array<int, array<string, mixed>>  $review
     * @param  array<int, array<string, mixed>>  $totals
     */
    protected function reconciliation(array $import, array $review, array $totals): void
    {
        $this->newLine();
        $this->heading('RECONCILIATION');
        $this->line(sprintf('Imported as expenses:           %s', $this->money($this->sum($import))));
        $this->line(sprintf('Excluded (income and review):   %s', $this->money($this->sum($review))));
        $this->line(sprintf('Everything the document spends: %s', $this->money($this->sum($import))));
        $this->newLine();
        $this->line('Totals written in the document, for comparison only:');

        foreach ($totals as $total) {
            $this->line(sprintf('  %-10s %-14s %s', 'R'.$total['row'].' C'.$total['column'],
                $this->money((float) $total['amount']), '"'.$total['original'].'"'));
        }

        $this->newLine();
        $this->warn('No attempt is made to make any of these agree.');
        $this->line('What each document total covers has not been established, so the difference');
        $this->line('between them and what is read here is left as a difference, not closed.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $import
     * @param  array<int, array<string, mixed>>  $already
     */
    protected function categoryTotals(array $import, array $already): void
    {
        $rows = collect([...$import, ...$already])
            ->groupBy('category_code')
            ->map(fn ($group, $code) => [
                $code,
                $group->count(),
                $this->money($group->sum('amount')),
            ])
            ->sortByDesc(fn ($row) => (float) str_replace([',', '$'], '', $row[2]))
            ->values()
            ->all();

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->heading('ENTRIES BY CATEGORY');
        $this->table(['Category', 'Entries', 'Total'], $rows);
    }

    /** @param  array<string, mixed>  $plan */
    protected function missingCategories(array $plan): void
    {
        $missing = $plan['categories_missing'] ?? [];

        if ($missing === []) {
            return;
        }

        $this->newLine();
        $this->heading('CATEGORIES THIS IMPORT WOULD CREATE');
        $this->line('Every other category it uses is one the school already has.');
        $this->table(['Code', 'Name', 'Somali'], array_map(
            fn ($code, $names) => [$code, $names[0], $names[1] ?? ''],
            array_keys($missing), $missing,
        ));
    }

    /** @param  array<int, array<string, mixed>>  $entries */
    protected function exclusions(string $title, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $this->newLine();
        $this->heading($title);
        $this->table(['Row', 'Original line', 'Amount', 'Why'], array_map(fn ($e) => [
            sprintf('R%d C%d', $e['row'], $e['column']),
            $e['original'],
            $e['amount'] === null ? '—' : $this->money($e['amount']),
            ($e['reason_code'] ? $e['reason_code'].' — ' : '').$e['reason'] ?: ($e['category_rule']
                ? __('Category :code chosen from ":word"', ['code' => $e['category_code'], 'word' => $e['category_rule']])
                : __('No rule matched — filed under :code', ['code' => $e['category_code']])),
        ], $entries));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $dated
     */
    protected function dateNote(array $plan, array $dated): void
    {
        $this->newLine();
        $this->heading('DATE STRATEGY');
        $this->line(sprintf(
            'The document is headed %s, and that is the only year it states.',
            $plan['document_date']?->toDateString() ?? 'no date at all',
        ));
        $this->line(sprintf(
            '%d lines carry a day and month of their own ("Shidaal geyr 28/6"); those are read as %d dates.',
            count($dated), $plan['year'],
        ));
        $this->line('Every other line carries no date. None is invented for it:');
        $this->line(sprintf('  all undated lines are dated %s, and each says so in its notes.', $plan['undated_date']->toDateString()));
        $this->line('  Pass --undated-date=YYYY-MM-DD to use a different one.');

        if ($dated !== []) {
            $spans = collect($dated)->pluck('date')->sort();
            $this->line(sprintf('  The dated lines run %s to %s.', $spans->first(), $spans->last()));
        }
    }

    /** @param  array<string, mixed>  $plan */
    protected function preview(array $plan): void
    {
        $limit = (int) $this->option('preview');
        $entries = $plan['entries'];
        $shown = $limit > 0 ? array_slice($entries, 0, $limit) : $entries;

        $this->newLine();
        $this->heading(sprintf('FULL PREVIEW — EVERY LINE, %d OF THEM', count($entries)));

        $this->table(
            ['Row', 'Date', 'Original description', 'Category', 'Amount', 'Import reference', 'Decision'],
            array_map(fn ($e) => [
                sprintf('R%d C%d', $e['row'], $e['column']),
                $e['decision'] === AlphaExpenseImporter::IMPORT_EXPENSE ? $e['date'].($e['date_source'] === 'line' ? '' : ' *') : '—',
                mb_substr($e['original'], 0, 40),
                $e['category_code'] ?: '—',
                $e['amount'] === null ? '—' : number_format((float) $e['amount'], 2),
                str_replace(config('alpha_expense_import.reference_prefix').':', '', $e['reference']),
                $e['decision'],
            ], $shown),
        );

        $this->line('  * dated by the fallback, not by the document.');

        if (count($shown) < count($entries)) {
            $this->line(sprintf('  … and %d more. Pass --preview=0 to print them all.', count($entries) - count($shown)));
        }
    }

    /* ------------------------------------------------------------------
       Small helpers
       ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $plan
     * @return array<int, array<string, mixed>>
     */
    protected function decided(array $plan, string $decision): array
    {
        return array_values(array_filter($plan['entries'], fn ($e) => $e['decision'] === $decision));
    }

    /** @param  array<int, array<string, mixed>>  $entries */
    protected function sum(array $entries): float
    {
        return round(array_sum(array_map(fn ($e) => (float) ($e['amount'] ?? 0), $entries)), 2);
    }

    protected function money(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }

    /** The admin the imported expenses are attributed to, when there is one. */
    protected function actor(): ?User
    {
        return User::whereHas('role', fn ($q) => $q->where('name', Role::ADMIN))
            ->orderBy('id')
            ->first();
    }

    protected function heading(string $title): void
    {
        $this->line(str_repeat('=', 78));
        $this->line($title);
        $this->line(str_repeat('=', 78));
    }
}
