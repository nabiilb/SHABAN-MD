<?php

namespace App\Services;

use App\Exceptions\ImportNotPersisted;
use App\Models\CompanyExpense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\DocumentNumber;
use App\Support\DocxReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads the school's handwritten Word expense ledger and turns it into
 * CompanyExpense rows.
 *
 * The ledger is one table of two description/amount column pairs written side
 * by side, in Somali, spelled as it was typed. Three things in it are not
 * expenses and the importer has to tell them apart from the ninety-odd lines
 * that are: the totals that summarise the lines above them, a line of money
 * that came in rather than went out, and a handful of lines missing either an
 * amount or a description. Everything else — including the lines that read as
 * personal rather than commercial, which the school has confirmed are its
 * spending — becomes an expense.
 *
 * parse() opens no write. It returns the plan, and the command prints it, so
 * the document can be read and argued with before anything is created. Every
 * judgement it makes lives in config/alpha_expense_import.php.
 */
class AlphaExpenseImporter
{
    public const IMPORT_EXPENSE = 'IMPORT_EXPENSE';

    /**
     * Never used, and kept so the report can say why.
     *
     * A fuel record needs a vehicle, a number of litres and a price per litre,
     * none of which is nullable and none of which the ledger writes down. It
     * also carries company_expense_id, because FuelService posts the expense
     * when a record is approved and the dashboard counts fuel through that
     * expense — so a fuel line imported here belongs in company_expenses under
     * the fuel category, exactly where it is put. Creating both would count
     * the same tank twice.
     */
    public const IMPORT_FUEL = 'IMPORT_FUEL';

    public const DUPLICATE = 'DUPLICATE';

    public const NEEDS_REVIEW = 'NEEDS_REVIEW';

    public const SKIP_INCOME = 'SKIP_INCOME';

    public const SKIP_TOTAL = 'SKIP_TOTAL';

    public const SKIP_HEADING = 'SKIP_HEADING';

    public const INVALID = 'INVALID';

    /**
     * Reads the document and decides what would happen to every line of it.
     *
     * Touches no database at all, so the dry run can be trusted to be one.
     *
     * @param  array{undated_date?: string|null, year?: int|null}  $overrides
     * @return array<string, mixed>
     */
    public function parse(string $path, array $overrides = []): array
    {
        $document = DocxReader::read($path);

        $documentDate = $this->documentDate($document['paragraphs']);

        $year = $overrides['year']
            ?? config('alpha_expense_import.year')
            ?? $documentDate?->year;

        $undated = $this->undatedDate($overrides, $documentDate);

        $entries = [];
        $blank = 0;

        foreach ($document['rows'] as $number => $cells) {
            foreach (config('alpha_expense_import.column_pairs') as $pair) {
                $entry = $this->entry(
                    $number,
                    $pair['description'],
                    $cells[$pair['description']] ?? '',
                    $cells[$pair['amount']] ?? '',
                    $year,
                    $undated,
                );

                $entry === null ? $blank++ : $entries[] = $entry;
            }
        }

        return [
            'source' => $path,
            'source_label' => config('alpha_expense_import.source_label'),
            'document_date' => $documentDate,
            'year' => $year,
            'undated_date' => $undated,
            'rows_read' => count($document['rows']),
            'pairs_inspected' => count($document['rows']) * count(config('alpha_expense_import.column_pairs')),
            'blank' => $blank,
            'entries' => $entries,
        ];
    }

    /**
     * Marks the lines already on file, so a second run has nothing to do.
     *
     * Reads; writes nothing.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function reconcile(array $plan): array
    {
        $imported = $this->importedReferences();

        $plan['entries'] = array_map(function (array $entry) use ($imported) {
            if ($entry['decision'] === self::IMPORT_EXPENSE && isset($imported[$entry['reference']])) {
                $entry['decision'] = self::DUPLICATE;
                $entry['reason'] = __('Already on file as :number', ['number' => $imported[$entry['reference']]]);
            }

            return $entry;
        }, $plan['entries']);

        $plan['categories_missing'] = $this->missingCategories($plan);

        return $plan;
    }

    /**
     * Writes the plan.
     *
     * One transaction, and every line checked against what is already on file
     * first: running this twice creates nothing the second time.
     *
     * @param  array<string, mixed>  $plan
     * @return array{created:int, already:int, skipped:int, categories_created:array<int, string>, failures:array<int, array<string, string>>, total:float}
     */
    public function apply(array $plan, ?User $actor = null): array
    {
        [$result, $written] = DB::transaction(fn () => $this->write($plan, $actor));

        // Committed by here, and read back through the query builder rather
        // than through the models that were just told. A count of create()
        // calls is not a count of rows on file.
        $missed = $this->unpersisted($written);

        if ($missed !== []) {
            throw new ImportNotPersisted($missed);
        }

        $result['created'] = count($written);
        $result['verified'] = count($written);
        $result['total'] = round(array_sum(array_column($written, 'amount')), 2);

        return $result;
    }

    /**
     * The writing half, inside the transaction.
     *
     * @param  array<string, mixed>  $plan
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    protected function write(array $plan, ?User $actor): array
    {
        $result = [
            'created' => 0,
            'verified' => 0,
            'already' => 0,
            'skipped' => 0,
            'categories_created' => [],
            'failures' => [],
            'total' => 0.0,
        ];

        $categories = $this->resolveCategories($plan, $result);
        $imported = $this->importedReferences();
        $written = [];

        foreach ($plan['entries'] as $entry) {
            if (! in_array($entry['decision'], [self::IMPORT_EXPENSE, self::DUPLICATE], true)) {
                $result['skipped']++;

                continue;
            }

            if (isset($imported[$entry['reference']])) {
                $result['already']++;

                continue;
            }

            try {
                $expense = CompanyExpense::create([
                    'expense_number' => DocumentNumber::next(CompanyExpense::class, 'expense_number', 'EXP'),
                    'expense_category_id' => $categories[$entry['category_code']],
                    'description' => mb_substr($entry['description'], 0, 200),
                    'amount' => $entry['amount'],
                    'expense_date' => $entry['date'],
                    'payment_method' => config('alpha_expense_import.payment_method', 'cash'),
                    'notes' => $this->notes($entry, $plan),
                    'created_by' => $actor?->id,
                ]);

                $imported[$entry['reference']] = $expense->expense_number;

                $written[$expense->getKey()] = [
                    'reference' => $entry['reference'],
                    'amount' => (float) $entry['amount'],
                    'category_code' => $entry['category_code'],
                ];
            } catch (Throwable $e) {
                $result['failures'][] = [
                    'reference' => $entry['reference'],
                    'description' => $entry['description'],
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [$result, $written];
    }

    /**
     * The rows that are not on file with the amount and marker they were
     * written with.
     *
     * @param  array<int, array<string, mixed>>  $written
     * @return array<int, array<string, mixed>>
     */
    protected function unpersisted(array $written): array
    {
        if ($written === []) {
            return [];
        }

        $missed = [];

        foreach (array_chunk($written, 200, true) as $chunk) {
            $onFile = DB::table('company_expenses')
                ->whereIn('id', array_keys($chunk))
                ->get(['id', 'amount', 'notes'])
                ->keyBy('id');

            foreach ($chunk as $id => $expected) {
                $row = $onFile->get($id);

                if ($row === null) {
                    $missed[] = ['id' => $id, 'field' => 'row', 'expected' => $expected['reference'], 'actual' => '(no such row)'];

                    continue;
                }

                if (round((float) $row->amount, 2) !== round($expected['amount'], 2)) {
                    $missed[] = ['id' => $id, 'field' => 'amount', 'expected' => $expected['amount'], 'actual' => $row->amount];
                }

                if (! str_contains((string) $row->notes, '['.$expected['reference'].']')) {
                    $missed[] = ['id' => $id, 'field' => 'import reference', 'expected' => $expected['reference'], 'actual' => '(marker not on file)'];
                }
            }
        }

        return $missed;
    }

    /* ------------------------------------------------------------------
       Reading one line
       ------------------------------------------------------------------ */

    /**
     * One description/amount pair, read and ruled on.
     *
     * Returns null when both cells are empty — the ledger is padded with blank
     * rows, and half of every row is empty for the stretch where only the
     * left-hand column was in use.
     *
     * @return array<string, mixed>|null
     */
    protected function entry(int $row, int $column, string $descriptionCell, string $amountCell, ?int $year, CarbonImmutable $undated): ?array
    {
        $descriptionCell = trim($descriptionCell);
        $amountCell = trim($amountCell);

        if ($descriptionCell === '' && $amountCell === '') {
            return null;
        }

        $original = trim($descriptionCell.' | '.$amountCell, ' |');

        // "1500 | Gaari iib ah" — the one line written the other way round.
        if ($this->isPlainNumber($descriptionCell) && ! $this->hasDigit($amountCell) && $amountCell !== '') {
            [$descriptionCell, $amountCell] = [$amountCell, $descriptionCell];
        }

        $descriptionCell = $this->stripRowNumber($descriptionCell);

        [$date, $descriptionCell, $amountCell] = $this->takeDate($descriptionCell, $amountCell, $year);

        [$amount, $extra] = $this->takeAmount($amountCell);

        // The description keeps its own numbers unless the amount column had
        // none — a total written as "TOTAL=978" carries its figure inline, an
        // ordinary description does not, and mining every description for
        // digits would eat the dates and door numbers in the wording.
        if ($amount === null) {
            [$amount, $descriptionCell] = $this->takeInlineAmount($descriptionCell);
        }

        $description = trim($descriptionCell);
        $extra = trim($extra);

        $entry = [
            'row' => $row,
            'column' => $column,
            'reference' => sprintf('%s:R%04d:C%d', config('alpha_expense_import.reference_prefix'), $row, $column),
            'original' => $original,
            'description' => $description !== '' ? $description : $extra,
            'extra' => $description !== '' ? $extra : '',
            'amount' => $amount,
            'date' => ($date ?? $undated)->toDateString(),
            'date_source' => $date ? 'line' : 'fallback',
            'category_code' => null,
            'category_rule' => null,
            'review' => false,
            'decision' => self::IMPORT_EXPENSE,
            'reason_code' => null,
            'reason' => null,
        ];

        return $this->decide($entry);
    }

    /**
     * What the line is: spending, a summary of other spending, money coming
     * in, or something the importer will not rule on by itself.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function decide(array $entry): array
    {
        $haystack = $this->normalise($entry['description'].' '.$entry['extra']);

        // 1. A total counts money already counted on the lines above it.
        foreach ((array) config('alpha_expense_import.total_words') as $word) {
            if ($this->mentions($haystack, $word)) {
                return $this->rule($entry, self::SKIP_TOTAL, 'SUMMARY_TOTAL',
                    __('Summary line (":word") — its amount is already counted in the lines above it', ['word' => $word]));
            }
        }

        // 2. A heading names a section of the book, not a cost in it.
        foreach ((array) config('alpha_expense_import.heading_words') as $word) {
            if ($this->mentions($haystack, $word)) {
                return $this->rule($entry, self::SKIP_HEADING, 'HEADING',
                    __('A heading, not an entry'));
            }
        }

        // 3. Nothing to post.
        if ($entry['amount'] === null) {
            return $this->rule($entry, self::INVALID, 'MISSING_AMOUNT',
                __('No amount is written beside this line'));
        }

        if ($entry['amount'] <= 0) {
            return $this->rule($entry, self::INVALID, 'NOT_A_POSITIVE_AMOUNT',
                __('The amount is not a positive figure'));
        }

        if ($entry['description'] === '') {
            return $this->rule($entry, self::INVALID, 'NO_DESCRIPTION',
                __('An amount with nothing written against it'));
        }

        // 4. Money that came in. Posting a receipt as an expense would charge
        //    the school for being paid.
        foreach ((array) config('alpha_expense_import.incoming_words') as $word) {
            if ($this->mentions($haystack, $word)) {
                return $this->rule($entry, self::SKIP_INCOME, 'INCOMING_MONEY',
                    __('Money received (":word"), not money spent', ['word' => $word]));
            }
        }

        // 5. Everything else is the school's spending. This is the owner's
        //    ruling: the document is the school's expense book, so a line in
        //    it is a cost, whether or not the wording says what it bought.
        [$entry['category_code'], $entry['category_rule']] = $this->categorise($haystack);

        if ($entry['date_source'] === 'fallback' && config('alpha_expense_import.undated_policy') === 'review') {
            return $this->rule($entry, self::NEEDS_REVIEW, 'NO_DATE',
                __('The document gives no date for this line'));
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function rule(array $entry, string $decision, string $code, string $reason): array
    {
        $entry['decision'] = $decision;
        $entry['reason_code'] = $code;
        $entry['reason'] = $reason;

        return $entry;
    }

    /**
     * The category and the word that chose it.
     *
     * Never nothing: a line nothing recognises is filed under Other Expense,
     * because not knowing what something bought is a reason to shelve it
     * carefully, not a reason to leave it out of the books.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function categorise(string $haystack): array
    {
        foreach ((array) config('alpha_expense_import.categories.rules') as $rule) {
            foreach ($rule['words'] as $word) {
                if (! $this->mentions($haystack, $word)) {
                    continue;
                }

                if (($rule['needs'] ?? null) === 'vehicle' && ! $this->namesAVehicle($haystack)) {
                    continue;
                }

                return [$rule['code'], $word];
            }
        }

        return [config('alpha_expense_import.categories.fallback', 'other_expense'), null];
    }

    protected function namesAVehicle(string $haystack): bool
    {
        foreach ((array) config('alpha_expense_import.categories.vehicle_words') as $word) {
            if ($this->mentions($haystack, $word)) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------
       Pulling figures and dates out of the wording
       ------------------------------------------------------------------ */

    /**
     * The first day/month written into either cell, as a date in the
     * document's year, with that text removed from both.
     *
     * @return array{0: CarbonImmutable|null, 1: string, 2: string}
     */
    protected function takeDate(string $description, string $amount, ?int $year): array
    {
        if ($year === null) {
            return [null, $description, $amount];
        }

        $date = null;

        foreach (['description', 'amount'] as $field) {
            $text = $field === 'description' ? $description : $amount;

            $replaced = preg_replace_callback(
                '/(?<![\d.])(\d{1,2})\s*\/\s*(\d{1,2})(?![\d.])/u',
                function (array $m) use (&$date, $year) {
                    if ($date !== null) {
                        return $m[0];
                    }

                    [$day, $month] = config('alpha_expense_import.day_first', true)
                        ? [(int) $m[1], (int) $m[2]]
                        : [(int) $m[2], (int) $m[1]];

                    if (! checkdate($month, $day, $year)) {
                        return $m[0];
                    }

                    $date = CarbonImmutable::create($year, $month, $day);

                    return ' ';
                },
                $text,
            ) ?? $text;

            $field === 'description' ? $description = $replaced : $amount = $replaced;
        }

        return [$date, $this->tidy($description), $this->tidy($amount)];
    }

    /**
     * The figure in an amount cell, and whatever words were written beside it.
     *
     * The ledger writes "2 dollar", "4.5 bajaj", "50 lesien" and, where a
     * finger slipped, "26 .75". A leading "=" is how the writer marked a
     * total; it is kept out of the figure and the line is excluded elsewhere.
     *
     * @return array{0: float|null, 1: string}
     */
    protected function takeAmount(string $cell): array
    {
        $cell = ltrim($this->tidy($cell), '=');

        if (! preg_match('/\d+(?:\s*[.,]\s*\d+)?/u', $cell, $match, PREG_OFFSET_CAPTURE)) {
            return [null, $this->tidy($cell)];
        }

        $figure = (float) str_replace([' ', ','], ['', '.'], $match[0][0]);

        $rest = substr_replace($cell, ' ', $match[0][1], strlen($match[0][0]));

        return [$figure, $this->tidy($rest)];
    }

    /**
     * A figure written inside the description itself, as totals are.
     *
     * @return array{0: float|null, 1: string}
     */
    protected function takeInlineAmount(string $description): array
    {
        [$amount, $rest] = $this->takeAmount($description);

        if ($amount === null) {
            return [null, $description];
        }

        return [$amount, $rest];
    }

    protected function stripRowNumber(string $text): string
    {
        return $this->tidy(preg_replace('/^\s*\d{1,3}\s*[:.)]\s*/u', '', $text) ?? $text);
    }

    protected function isPlainNumber(string $text): bool
    {
        return (bool) preg_match('/^\d+(?:[.,]\d+)?$/u', trim($text));
    }

    protected function hasDigit(string $text): bool
    {
        return (bool) preg_match('/\d/u', $text);
    }

    protected function tidy(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Whether a keyword is written in the line, as a word rather than as
     * letters that happen to sit inside a longer one.
     *
     * The ledger's spelling is loose enough that the rules have to match on
     * stems — "samey" has to find "sameyntis" — so a keyword matches from the
     * start of a word onwards but never from its middle. Without that,
     * "taayo" finds the tyres inside "Notaayo", and a notary's fee is filed
     * as a set of tyres.
     */
    protected function mentions(string $haystack, string $word): bool
    {
        $needle = $this->normalise($word);

        if ($needle === '') {
            return false;
        }

        return (bool) preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'/u', $haystack);
    }

    /** Lower case, punctuation flattened to spaces: what the rules match on. */
    protected function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /* ------------------------------------------------------------------
       The document's own date, and the date undated lines are given
       ------------------------------------------------------------------ */

    /** @param  array<int, string>  $paragraphs */
    protected function documentDate(array $paragraphs): ?CarbonImmutable
    {
        foreach ($paragraphs as $paragraph) {
            if (! preg_match('/(\d{1,2})\s*[-\/.]\s*(\d{1,2})\s*[-\/.]\s*(\d{4})/u', $paragraph, $m)) {
                continue;
            }

            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];

            if (! config('alpha_expense_import.day_first', true) || ! checkdate($month, $day, $year)) {
                [$day, $month] = [$month, $day];
            }

            if (checkdate($month, $day, $year)) {
                return CarbonImmutable::create($year, $month, $day);
            }
        }

        return null;
    }

    /** @param  array{undated_date?: string|null}  $overrides */
    protected function undatedDate(array $overrides, ?CarbonImmutable $documentDate): CarbonImmutable
    {
        $configured = $overrides['undated_date'] ?? config('alpha_expense_import.undated_date');

        if ($configured) {
            return CarbonImmutable::parse($configured)->startOfDay();
        }

        return $documentDate ?? CarbonImmutable::today();
    }

    /* ------------------------------------------------------------------
       Categories and what is already on file
       ------------------------------------------------------------------ */

    /**
     * The import's own references, as reference => expense number.
     *
     * Read in one query and matched in PHP: a LIKE per line would be a hundred
     * round trips, and an exact comparison here cannot be tripped by a
     * reference that happens to be the start of another.
     *
     * @return array<string, string>
     */
    protected function importedReferences(): array
    {
        $prefix = config('alpha_expense_import.reference_prefix');

        return CompanyExpense::withTrashed()
            ->where('notes', 'like', '%['.$prefix.':%')
            ->pluck('notes', 'expense_number')
            ->reduce(function (array $carry, string $notes, string $number) use ($prefix) {
                if (preg_match('/\['.preg_quote($prefix, '/').':[^\]]+\]/u', $notes, $m)) {
                    $carry[trim($m[0], '[]')] = $number;
                }

                return $carry;
            }, []);
    }

    /**
     * Categories the plan needs that the database does not have yet.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, array{0: string, 1: string}>
     */
    protected function missingCategories(array $plan): array
    {
        $needed = collect($plan['entries'])
            ->where('decision', self::IMPORT_EXPENSE)
            ->pluck('category_code')
            ->filter()
            ->unique();

        $existing = ExpenseCategory::whereIn('code', $needed)->pluck('code');

        return collect(config('alpha_expense_import.categories.new'))
            ->only($needed->diff($existing))
            ->all();
    }

    /**
     * Every category the plan needs, as code => id, creating the ones the
     * school does not have through the same model the admin screens use.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $result
     * @return array<string, int>
     */
    protected function resolveCategories(array $plan, array &$result): array
    {
        $names = (array) config('alpha_expense_import.categories.new');
        $resolved = [];

        $codes = collect($plan['entries'])
            ->where('decision', self::IMPORT_EXPENSE)
            ->pluck('category_code')
            ->filter()
            ->unique();

        $sortOrder = (int) ExpenseCategory::max('sort_order');

        foreach ($codes as $code) {
            $category = ExpenseCategory::where('code', $code)->first();

            if (! $category) {
                [$name, $nameSo] = $names[$code] ?? [ucwords(str_replace('_', ' ', $code)), null];

                $category = ExpenseCategory::create([
                    'code' => $code,
                    'name' => $name,
                    'name_so' => $nameSo,
                    'is_active' => true,
                    'sort_order' => ++$sortOrder,
                ]);

                $result['categories_created'][] = $code;
            }

            $resolved[$code] = $category->id;
        }

        return $resolved;
    }

    /**
     * What the expense carries about where it came from: the wording exactly
     * as the ledger has it, what the importer did about the date, and the
     * reference that stops it being imported twice.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $plan
     */
    protected function notes(array $entry, array $plan): string
    {
        $lines = [
            __('Imported from :source, row :row column :column.', [
                'source' => $plan['source_label'],
                'row' => $entry['row'],
                'column' => $entry['column'],
            ]),
            __('Original: :text', ['text' => '"'.$entry['original'].'"']),
            $entry['date_source'] === 'line'
                ? __('Date: written on the line.')
                : __('Date: not stated in the document; dated :date as a placeholder.', ['date' => $entry['date']]),
            '['.$entry['reference'].']',
        ];

        return implode("\n", $lines);
    }
}
