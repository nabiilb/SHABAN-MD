<?php

namespace Tests\Feature;

use App\Models\CompanyExpense;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\Vehicle;
use App\Services\AlphaExpenseImporter;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use ZipArchive;

/**
 * Importing the school's handwritten Word expense ledger.
 *
 * Most of these build their own small document, so each one states the exact
 * lines it is about; the last few run against the real ledger, where the
 * spelling, the totals and the one line of money coming in are as they were
 * actually typed.
 */
class AlphaExpenseImportTest extends TestCase
{
    use RefreshDatabase;

    private string $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-18 09:00:00'));

        $this->makeUser(Role::ADMIN);

        $this->document = storage_path('app/imports/DEYNTA BISHI AAN ISTICMAALNAY ALPHA DRIVING SCHOOL.docx');
    }

    /* ------------------------------------------------------------------
       Reading the ledger
       ------------------------------------------------------------------ */

    public function test_it_reads_an_ordinary_expense_line(): void
    {
        $plan = $this->parse([['shidaal geyr', '52']]);

        $entry = $plan['entries'][0];

        $this->assertSame(AlphaExpenseImporter::IMPORT, $entry['decision']);
        $this->assertSame('shidaal geyr', $entry['description']);
        $this->assertSame(52.0, $entry['amount']);
        $this->assertSame('fuel', $entry['category_code']);
        $this->assertSame('ALPHA-EXPENSE-IMPORT:R0001:C1', $entry['reference']);
    }

    public function test_it_keeps_decimal_amounts_to_the_cent(): void
    {
        $plan = $this->parse([
            ['qado', '1.31'],
            ['caraf xafiiska', '5.25'],
            ['Shidaal otomatic', '56.38'],
            ['Shidag gariga cusub', '44.4'],
            ['OTOMATIC CUSUB', '10.3'],
            ['Otomatic hore', '26.75'],
        ]);

        $this->assertSame(
            [1.31, 5.25, 56.38, 44.4, 10.3, 26.75],
            array_column($plan['entries'], 'amount'),
        );
    }

    public function test_it_reads_a_figure_typed_with_a_space_before_the_decimal_point(): void
    {
        // "26 .75" is one figure split by a slipped finger, not 26 and 75.
        $plan = $this->parse([['Geyr', '26 .75']]);

        $this->assertSame(26.75, $plan['entries'][0]['amount']);
    }

    public function test_it_reads_a_figure_and_a_date_typed_on_two_lines_of_one_cell(): void
    {
        // Word keeps these as two paragraphs. Run together they read as 2712.
        $plan = $this->parse([['Gaariga otomatic hore', ['27', '12/8']]]);

        $this->assertSame(27.0, $plan['entries'][0]['amount']);
        $this->assertSame('2026-08-12', $plan['entries'][0]['date']);
    }

    public function test_it_keeps_the_words_written_beside_an_amount(): void
    {
        $plan = $this->parse([['Sakariye', '4.5 bajaj']]);

        $entry = $plan['entries'][0];

        $this->assertSame(4.5, $entry['amount']);
        $this->assertSame('Sakariye', $entry['description']);
        $this->assertSame('Sakariye | 4.5 bajaj', $entry['original']);
        // The words say what the money was for, so they choose the category.
        $this->assertSame('transport', $entry['category_code']);
    }

    public function test_the_same_expense_written_twice_is_imported_twice(): void
    {
        $plan = $this->parse([['Shidaal', '50'], ['Shidaal', '50']]);

        $this->assertCount(2, $plan['entries']);
        $this->assertSame(AlphaExpenseImporter::IMPORT, $plan['entries'][0]['decision']);
        $this->assertSame(AlphaExpenseImporter::IMPORT, $plan['entries'][1]['decision']);
        $this->assertNotSame($plan['entries'][0]['reference'], $plan['entries'][1]['reference']);

        $this->apply($plan);

        $this->assertSame(2, CompanyExpense::where('description', 'Shidaal')->count());
        $this->assertSame('100.00', (string) CompanyExpense::sum('amount'));
    }

    /* ------------------------------------------------------------------
       Lines that are not expenses
       ------------------------------------------------------------------ */

    public function test_it_excludes_summary_totals(): void
    {
        $plan = $this->parse([
            ['Shidaal', '50'],
            ['wadarta', '=1091.88'],
            ['TOTAL', '1151.5'],
            ['TOTAL=978', ''],
        ]);

        $decisions = array_column($plan['entries'], 'decision');

        $this->assertSame([
            AlphaExpenseImporter::IMPORT,
            AlphaExpenseImporter::EXCLUDE_TOTAL,
            AlphaExpenseImporter::EXCLUDE_TOTAL,
            AlphaExpenseImporter::EXCLUDE_TOTAL,
        ], $decisions);

        $this->apply($plan);

        // Only the one real line; the summaries would have counted it again.
        $this->assertSame(1, CompanyExpense::count());
        $this->assertSame('50.00', (string) CompanyExpense::sum('amount'));
    }

    public function test_it_excludes_money_that_came_in(): void
    {
        $plan = $this->parse([['Deyn soo xarootay', '220'], ['Shidaal', '50']]);

        $this->assertSame(AlphaExpenseImporter::EXCLUDE_INCOMING_MONEY, $plan['entries'][0]['decision']);

        $this->apply($plan);

        $this->assertSame(0, CompanyExpense::where('description', 'Deyn soo xarootay')->count());
        $this->assertSame('50.00', (string) CompanyExpense::sum('amount'));
    }

    public function test_a_line_with_no_figure_is_skipped_rather_than_guessed_at(): void
    {
        $plan = $this->parse([
            ['Xiisbtii bisha hore taalay', ''],
            ['Geyr 10/8', ''],
        ]);

        foreach ($plan['entries'] as $entry) {
            $this->assertSame(AlphaExpenseImporter::SKIPPED_MISSING_AMOUNT, $entry['decision']);
            $this->assertSame('MISSING_AMOUNT', $entry['reason_code']);
            $this->assertNotNull($entry['reason']);
        }

        $this->apply($plan);

        $this->assertSame(0, CompanyExpense::count());
    }

    /* ------------------------------------------------------------------
       Categories
       ------------------------------------------------------------------ */

    public function test_informal_and_personal_looking_lines_are_expenses_like_any_other(): void
    {
        $lines = [
            ['Aabbe', '15'], ['Ayeeyo', '50'], ['Kaafiya', '16'], ['nuuro', '50'],
            ['Abdullahi', '2'], ['Sakariye', '13'], ['Siciid', '50'], ['yaxye', '10'],
            ['Liibaan', '45'], ['Macalinka quranka', '25'], ['Kabo abdalle', '10'],
            ['qado', '1.31'], ['Abdullhi', '2.5 qurac'], ['Marti', '12'],
            ['Adeeg guri', '55'], ['Internet guri', '23'],
        ];

        $plan = $this->parse($lines);

        foreach ($plan['entries'] as $entry) {
            $this->assertSame(
                AlphaExpenseImporter::IMPORT,
                $entry['decision'],
                "{$entry['original']} should be imported as an expense",
            );
        }

        $this->apply($plan);

        $this->assertSame(count($lines), CompanyExpense::count());
    }

    public function test_it_files_fuel_as_fuel_however_it_is_spelled(): void
    {
        $plan = $this->parse([
            ['shidaal geyr', '52'], ['Shidal otomatoc', '55'], ['ShidL', '50'],
            ['shiidl', '20'], ['Shidag gariga cusub', '44.4'], ['Geyr shidak', '50'],
        ]);

        foreach ($plan['entries'] as $entry) {
            $this->assertSame('fuel', $entry['category_code'], $entry['original']);
            $this->assertFalse($entry['review'], $entry['original'].' names the fuel outright');
        }
    }

    public function test_a_line_that_only_names_a_vehicle_is_filed_as_fuel_but_flagged(): void
    {
        $plan = $this->parse([['Otomatic hore', '25'], ['geyr', '25']]);

        foreach ($plan['entries'] as $entry) {
            $this->assertSame('fuel', $entry['category_code']);
            $this->assertTrue($entry['review'], 'the column it sits in is the only thing saying fuel');
        }
    }

    public function test_it_files_repairs_washing_oil_and_parts_apart(): void
    {
        $plan = $this->parse([
            ['Gaari sameyn', '25'],
            ['Gaari lagu hagaaji', '13'],
            ['Gaariga cusb hagajintisa', '110'],
            ['Shil gaari', '10'],
            ['Adeeg gaarigga', '114'],
            ['DHAQIS GAADIID', '50'],
            ['Dhaqid', '7.5'],
            ['Olyo badal', '20'],
            ['Geyr olyo dhaqis', '28'],
            ['Batari', '35'],
        ]);

        $this->assertSame([
            'vehicle_repair', 'vehicle_repair', 'vehicle_repair', 'vehicle_repair',
            'garage_service',
            'car_wash', 'car_wash',
            'oil_change', 'oil_change',
            'spare_parts',
        ], array_column($plan['entries'], 'category_code'));
    }

    public function test_a_keyword_is_never_matched_inside_a_longer_word(): void
    {
        // "Notaayo" is a notary's fee. It is not a set of taayo — tyres.
        $plan = $this->parse([['Notaayo', '50']]);

        $this->assertSame('certificates', $plan['entries'][0]['category_code']);
    }

    public function test_a_line_no_rule_matches_is_filed_under_other_and_imported_anyway(): void
    {
        $plan = $this->parse([['dilaal', '50'], ['Qasaalad', '135'], ['ADEEG', '30']]);

        foreach ($plan['entries'] as $entry) {
            $this->assertSame(AlphaExpenseImporter::IMPORT, $entry['decision']);
            $this->assertSame('other', $entry['category_code']);
            $this->assertNull($entry['category_rule']);
            // Informal wording is not a reason to hold an expense back.
            $this->assertFalse($entry['review']);
        }

        $this->apply($plan);

        $this->assertSame(3, CompanyExpense::count());
    }

    /* ------------------------------------------------------------------
       Buying a vehicle
       ------------------------------------------------------------------ */

    public function test_it_imports_the_vehicle_purchase_written_the_other_way_round(): void
    {
        // The ledger has the amount in the description column for this one.
        $plan = $this->parse([['1500', 'Gaari iib ah']]);

        $entry = $plan['entries'][0];

        $this->assertSame(AlphaExpenseImporter::IMPORT, $entry['decision']);
        $this->assertSame(1500.0, $entry['amount']);
        $this->assertSame('Gaari iib ah', $entry['description']);
        $this->assertSame('vehicle_purchase', $entry['category_code']);
        $this->assertSame('1500 | Gaari iib ah', $entry['original']);
    }

    public function test_buying_a_vehicle_creates_an_expense_and_touches_no_vehicle_record(): void
    {
        $before = Vehicle::withTrashed()->count();

        $this->apply($this->parse([['1500', 'Gaari iib ah']]));

        $expense = CompanyExpense::sole();

        $this->assertSame('1500.00', $expense->amount);
        $this->assertSame('vehicle_purchase', $expense->category->code);
        $this->assertNull($expense->vehicle_id);
        $this->assertSame($before, Vehicle::withTrashed()->count());
    }

    /* ------------------------------------------------------------------
       Dates and wording
       ------------------------------------------------------------------ */

    public function test_a_date_written_on_the_line_is_the_date_it_is_given(): void
    {
        $plan = $this->parse([['Shidaal geyr 28/6', '50']], '5-7-2026');

        $entry = $plan['entries'][0];

        $this->assertSame('2026-06-28', $entry['date']);
        $this->assertSame('line', $entry['date_source']);
        // The date is taken out of the wording, not left in it.
        $this->assertSame('Shidaal geyr', $entry['description']);
    }

    public function test_an_undated_line_is_given_one_date_and_says_so(): void
    {
        $this->apply($this->parse([['Shidaal', '50']], '5-7-2026'));

        $expense = CompanyExpense::sole();

        $this->assertSame('2026-07-05', $expense->expense_date->toDateString());
        $this->assertStringContainsString('not stated in the document', $expense->notes);
        $this->assertStringContainsString('placeholder', $expense->notes);
    }

    public function test_the_fallback_date_can_be_overridden(): void
    {
        $plan = $this->parse([['Shidaal', '50']], '5-7-2026', ['undated_date' => '2026-08-31']);

        $this->assertSame('2026-08-31', $plan['entries'][0]['date']);
    }

    public function test_the_original_wording_is_kept_on_the_expense(): void
    {
        $this->apply($this->parse([['Shidaal otomatic', '56.38']]));

        $expense = CompanyExpense::sole();

        $this->assertSame('Shidaal otomatic', $expense->description);
        $this->assertSame('56.38', $expense->amount);
        $this->assertStringContainsString('Shidaal otomatic | 56.38', $expense->notes);
        $this->assertStringContainsString('ALPHA-EXPENSE-IMPORT:R0001:C1', $expense->notes);
    }

    /* ------------------------------------------------------------------
       Writing, and not writing
       ------------------------------------------------------------------ */

    public function test_a_dry_run_writes_nothing_at_all(): void
    {
        $this->realLedger();

        $before = [
            'expenses' => CompanyExpense::withTrashed()->count(),
            'categories' => ExpenseCategory::count(),
        ];

        $this->artisan('alpha:import-expenses', ['file' => $this->document, '--dry-run' => true])
            ->expectsOutputToContain('NO DATABASE CHANGES WERE MADE')
            ->assertSuccessful();

        $this->assertSame($before['expenses'], CompanyExpense::withTrashed()->count());
        $this->assertSame($before['categories'], ExpenseCategory::count());
    }

    public function test_answering_no_at_the_prompt_writes_nothing(): void
    {
        $this->realLedger();

        $this->artisan('alpha:import-expenses', [
            'file' => $this->document, '--confirm' => true, '--preview' => 1,
        ])
            ->expectsConfirmation('Create 140 expenses totalling $5,965.64?', 'no')
            ->expectsOutputToContain('Nothing was imported')
            ->assertSuccessful();

        $this->assertSame(0, CompanyExpense::count());
    }

    public function test_the_real_import_refuses_to_run_without_being_asked(): void
    {
        $this->realLedger();

        $this->artisan('alpha:import-expenses', ['file' => $this->document])
            ->expectsOutputToContain('Refusing to guess')
            ->assertFailed();

        $this->artisan('alpha:import-expenses', [
            'file' => $this->document, '--dry-run' => true, '--confirm' => true,
        ])->expectsOutputToContain('not both')->assertFailed();

        $this->assertSame(0, CompanyExpense::count());
    }

    public function test_confirming_writes_the_ledger_once_and_only_once(): void
    {
        $this->realLedger();

        $this->artisan('alpha:import-expenses', [
            'file' => $this->document, '--confirm' => true, '--preview' => 1,
        ])
            ->expectsConfirmation('Create 140 expenses totalling $5,965.64?', 'yes')
            ->assertSuccessful();

        $first = CompanyExpense::count();
        $total = (string) CompanyExpense::sum('amount');

        $this->assertSame(140, $first);
        $this->assertSame('5965.64', $total);

        $this->artisan('alpha:import-expenses', [
            'file' => $this->document, '--confirm' => true, '--preview' => 1,
        ])->expectsOutputToContain('already on file')->assertSuccessful();

        $this->assertSame($first, CompanyExpense::count());
        $this->assertSame($total, (string) CompanyExpense::sum('amount'));
    }

    public function test_it_leaves_the_expenses_already_on_file_alone(): void
    {
        $existing = CompanyExpense::create([
            'expense_number' => 'EXP-0001',
            'expense_category_id' => ExpenseCategory::where('code', 'fuel')->value('id'),
            'description' => 'Diesel for the yard generator',
            'amount' => 77.77,
            'expense_date' => '2026-05-01',
            'payment_method' => 'cash',
            'notes' => 'Nothing to do with the ledger',
        ]);

        $before = $existing->fresh()->getAttributes();

        $this->apply($this->parse([['Shidaal', '50'], ['wadarta', '=1091.88']]));

        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertSame(2, CompanyExpense::count());
    }

    public function test_it_reuses_the_categories_the_school_already_has(): void
    {
        $fuel = ExpenseCategory::where('code', 'fuel')->sole();

        $this->apply($this->parse([['Shidaal', '50'], ['Aabbe', '15']]));

        // No second "Fuel", "fuel" or "Shidaal" category beside the first.
        $this->assertSame(1, ExpenseCategory::where('code', 'fuel')->count());
        $this->assertSame($fuel->id, CompanyExpense::where('description', 'Shidaal')->value('expense_category_id'));
        $this->assertSame('Fuel', $fuel->fresh()->name);

        // The one the school did not have is created through the same model.
        $this->assertSame('Home & General Support', ExpenseCategory::where('code', 'home_support')->value('name'));
    }

    public function test_imported_expenses_count_towards_the_dashboard_totals(): void
    {
        $this->apply($this->parse([['Shidaal', '50'], ['Aabbe', '15']]));

        $metrics = app(DashboardService::class)->adminMetrics();

        $this->assertSame(65.0, $metrics['total_expenses']);
    }

    /* ------------------------------------------------------------------
       The real ledger
       ------------------------------------------------------------------ */

    public function test_the_real_ledger_reads_as_the_school_wrote_it(): void
    {
        $this->realLedger();

        $plan = app(AlphaExpenseImporter::class)->parse($this->document);

        $by = fn (string $decision) => array_values(array_filter(
            $plan['entries'], fn ($e) => $e['decision'] === $decision,
        ));

        $this->assertSame(128, $plan['rows_read']);
        $this->assertCount(3, $by(AlphaExpenseImporter::EXCLUDE_TOTAL));
        $this->assertCount(1, $by(AlphaExpenseImporter::EXCLUDE_INCOMING_MONEY));
        $this->assertCount(2, $by(AlphaExpenseImporter::SKIPPED_MISSING_AMOUNT));
        // Everything else the school has ruled on.
        $this->assertCount(0, $by(AlphaExpenseImporter::NEEDS_REVIEW));

        $import = $by(AlphaExpenseImporter::IMPORT);
        $this->assertCount(140, $import);
        $this->assertSame(5965.64, round(array_sum(array_column($import, 'amount')), 2));

        // Nothing is imported without an amount, a description or a category.
        foreach ($import as $entry) {
            $this->assertGreaterThan(0, $entry['amount'], $entry['original']);
            $this->assertNotSame('', $entry['description'], $entry['original']);
            $this->assertNotNull($entry['category_code'], $entry['original']);
        }
    }

    /* ------------------------------------------------------------------
       Helpers
       ------------------------------------------------------------------ */

    /**
     * Reads a document built from the given rows.
     *
     * Each row is [description, amount] for the left-hand column pair, or
     * [description, amount, description, amount] to use both. A cell given as
     * an array becomes the several paragraphs Word would hold it in.
     *
     * @param  array<int, array<int, string|array<int, string>>>  $rows
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function parse(array $rows, string $heading = '5-7-2026', array $overrides = []): array
    {
        $path = $this->makeDocument($rows, $heading);

        return app(AlphaExpenseImporter::class)->reconcile(
            app(AlphaExpenseImporter::class)->parse($path, $overrides),
        );
    }

    /** @param  array<string, mixed>  $plan */
    private function apply(array $plan): array
    {
        return app(AlphaExpenseImporter::class)->apply($plan);
    }

    private function realLedger(): void
    {
        if (! is_readable($this->document)) {
            $this->markTestSkipped('The expense ledger is not present in storage/app/imports.');
        }
    }

    /**
     * Writes a Word document holding one table of the given rows.
     *
     * @param  array<int, array<int, string|array<int, string>>>  $rows
     */
    private function makeDocument(array $rows, string $heading): string
    {
        $body = '<w:p><w:r><w:t xml:space="preserve">'.$heading.'</w:t></w:r></w:p><w:tbl>';

        foreach ($rows as $row) {
            $body .= '<w:tr>';

            for ($column = 0; $column < 4; $column++) {
                $cell = $row[$column] ?? '';
                $paragraphs = '';

                foreach ((array) $cell as $line) {
                    $paragraphs .= '<w:p><w:r><w:t xml:space="preserve">'
                        .htmlspecialchars((string) $line, ENT_XML1)
                        .'</w:t></w:r></w:p>';
                }

                $body .= '<w:tc>'.($paragraphs ?: '<w:p/>').'</w:tc>';
            }

            $body .= '</w:tr>';
        }

        $body .= '</w:tbl>';

        $path = tempnam(sys_get_temp_dir(), 'ledger').'.docx';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$body.'</w:body></w:document>');
        $zip->close();

        return $path;
    }
}
