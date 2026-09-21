<?php

/*
|--------------------------------------------------------------------------
| Alpha historical expense import
|--------------------------------------------------------------------------
|
| The school's spending for the period was kept by hand in a Word table, in
| Somali, with the spelling of a ledger written at speed: shidaal, shidal,
| shidL, shiidl. Every judgement the importer makes about that document lives
| here, in the open, rather than inside the parser. A rule in this file is one
| somebody can read, argue with and revert; one buried in a regular expression
| is not.
|
| Nothing here is applied silently. The dry run prints the decision and the
| matched rule for every single line, so `alpha:import-expenses --dry-run`
| always shows the document exactly as it will be read.
|
*/

return [

    /*
    | Where the document lives, unless --file says otherwise.
    */
    'file' => storage_path('app/imports/DEYNTA BISHI AAN ISTICMAALNAY ALPHA DRIVING SCHOOL.docx'),

    /*
    |----------------------------------------------------------------------
    | The shape of the table
    |----------------------------------------------------------------------
    |
    | The ledger is one table whose four columns are two independent
    | description/amount pairs, written side by side to save paper. Each pair
    | is read as its own column of the ledger, so a row can hold two entries,
    | one, or none.
    |
    | Listed as the 1-based column the pair starts at.
    */
    'column_pairs' => [
        ['description' => 1, 'amount' => 2],
        ['description' => 3, 'amount' => 4],
    ],

    /*
    |----------------------------------------------------------------------
    | Dates
    |----------------------------------------------------------------------
    |
    | Some entries carry a day and month written into the line — "Shidaal geyr
    | 28/6", "Geyr 12/8" — and nothing carries a year. The year comes from the
    | date at the head of the document, which is the only year the document
    | states. Its day and month are ambiguous (5-7-2026 could be read either
    | way) but that does not matter: only the year is taken from it.
    |
    | Most entries carry no date at all. The importer does NOT guess one per
    | entry. Every undated entry is given the single fallback date below, that
    | fact is recorded in the expense's notes, and the dry run reports how many
    | entries it affects. Pass --undated-date=YYYY-MM-DD to choose another.
    |
    | The fallback is deliberately NOT the head-of-document date applied as if
    | it were true: the dated entries run from 28/6 to 16/8, straddling it, so
    | the document does not support treating it as the date of the entries. It
    | is a placeholder, and it is labelled as one on every row it touches.
    */
    'year' => null,                  // null = read the year from the document's first dated paragraph
    'undated_date' => null,          // null = fall back to the head-of-document date
    'day_first' => true,             // "28/6" is the 28th of June, not the 6th of the 28th

    /*
    |----------------------------------------------------------------------
    | Lines that are not expenses
    |----------------------------------------------------------------------
    |
    | Totals summarise the lines above them; importing one would count that
    | money twice. Incoming lines are money that came in, not money that went
    | out, and have no place in an expense ledger at all.
    |
    | Matched against the line with its case and punctuation stripped.
    */
    'total_words' => [
        'wadarta', 'wadar', 'total', 'subtotal', 'isku darka', 'isku wadarta', 'guud ahaan', 'grand total',
    ],

    'incoming_words' => [
        'deyn soo xarootay', 'soo xarootay', 'soo celiyay', 'soo galay', 'lacag soo gashay',
        'dakhli', 'la soo qaatay', 'income', 'received',
    ],

    /*
    |----------------------------------------------------------------------
    | Dates
    |----------------------------------------------------------------------
    |
    | 'fallback'  undated lines take the head-of-document date, labelled as a
    |             placeholder in every expense's notes.
    | 'review'    undated lines are not imported at all.
    |
    | The document is headed with one date and carries eleven days written
    | into individual lines. It has no dated sections: the days that do appear
    | run 1/8, 2/8, 5/8, 2/8, 8/8 — out of order, in the middle of a column —
    | so there is no "active date" for a line to inherit, and nothing is
    | inherited from the line above. A line either says its own date or has
    | none.
    */
    'undated_policy' => 'fallback',

    /*
    |----------------------------------------------------------------------
    | What is imported
    |----------------------------------------------------------------------
    |
    | Everything, unless it is one of four things: a total, a heading, a line
    | with no usable figure, or money coming in.
    |
    | This is the owner's ruling and it settles a question the ledger cannot:
    | the document is the school's expense book, so a line in it is the
    | school's spending, whether or not the wording says what it bought. An
    | earlier reading held every informally-worded line for review, which was
    | the cautious answer to a question that has now been answered.
    |
    | The decision is taken in this order, and the first that fits wins:
    |
    |   1. a total or subtotal      SKIP_TOTAL    its amount is counted above
    |   2. a heading                SKIP_HEADING  names a section, not a cost
    |   3. no usable positive amount INVALID      nothing to post
    |   4. money coming in          SKIP_INCOME   a receipt, not a payment
    |   5. anything else            IMPORT_EXPENSE
    |
    | A category is chosen from the wording where it can be, and where it
    | cannot the line is filed under Other Expense. Not knowing what something
    | bought never stops it being imported — it only decides which shelf it
    | goes on.
    */
    'categories' => [
        // Categories this import adds where the school does not have them.
        'new' => [
            'vehicle_purchase' => ['Vehicle Purchase', 'Iibka Gaariga'],
            'food_refreshments' => ['Food & Hospitality', 'Cunto & Marti-soor'],
            'certificates' => ['Certificates & Printing', 'Shahaadooyin & Daabacaad'],
            'advertising' => ['Advertising', 'Xayeysiin'],
            'transport' => ['Transport', 'Gaadiid'],
            'staff_support' => ['Staff & Personal Support', 'Taageero Shaqaale'],
            'household_operations' => ['Household & Operations', 'Guriga & Hawlaha'],
            'other_expense' => ['Other Expense', 'Kharash Kale'],
        ],

        // Where a line goes when nothing below recognises it.
        'fallback' => 'other_expense',

        /*
        | Tried in order, first match wins, matched against the description and
        | any words written in the amount cell beside it. The specific sit
        | above the general: a vehicle bought outright is not a repair, oil is
        | not a wash, and fuel for the house is still fuel.
        */
        'rules' => [
            // --- A vehicle bought outright. Capital, and named as such -----
            ['code' => 'vehicle_purchase', 'words' => ['gaari iib', 'gari iib', 'iib ah', 'gaari iibsi']],

            // --- Oil before washing: "olyo dhaqis" is an oil change --------
            ['code' => 'oil_change', 'words' => ['olyo', 'oleyo', 'oyl', 'oil']],
            ['code' => 'car_wash', 'words' => ['dhaqid', 'dhaqis', 'dhaqista', 'dhaqa']],

            // --- Parts before labour: a battery is a thing, not a repair ---
            ['code' => 'spare_parts', 'words' => ['batari', 'bateri', 'qaybo gaari']],
            ['code' => 'tires', 'words' => ['taayir', 'taayirro']],

            ['code' => 'fuel', 'words' => [
                'shidaal', 'shidal', 'shiddaal', 'shidl', 'shiidl', 'shidag', 'shidak', 'shidaa',
                'benziin', 'benzin', 'naft', 'fuel', 'petrol', 'diesel',
            ]],

            ['code' => 'vehicle_repair', 'words' => [
                'samey', 'hagaaji', 'hagajin', 'dayactir', 'shil', 'jabay', 'garaash', 'garage',
            ]],

            ['code' => 'garage_service', 'words' => ['adeeg gaari', 'adeeg gari', 'adeegga gaari', 'adeegga gari', 'adeeg gaarigga']],

            ['code' => 'office', 'words' => ['xafiis', 'xafis', 'xafiska', 'xafiiska', 'xafoska', 'office']],
            ['code' => 'internet', 'words' => ['internet', 'wifi', 'intarnet']],
            ['code' => 'electricity', 'words' => ['koronto', 'biyo', 'biil', 'biilka', 'bill', 'laydh']],
            ['code' => 'advertising', 'words' => ['xayeysin', 'xayeysiin', 'xayaysiin', 'bandhig', 'advert']],

            ['code' => 'certificates', 'words' => [
                'shahaado', 'shahaadooyin', 'notaayo', 'notary', 'lesien', 'laysan', 'license', 'licence',
                'busniss code', 'business code', 'daabacaad', 'print',
            ]],

            ['code' => 'food_refreshments', 'words' => [
                'qado', 'casho', 'quraac', 'qurac', 'shah', 'shaah', 'cabitan', 'cabbitaan',
                'cake', 'cacke', 'keeg', 'koofi', 'coffee', 'cunto', 'marti', 'biscuit',
            ]],

            ['code' => 'transport', 'words' => ['bajaj', 'taksi', 'taxi', 'baabuur kiro']],

            // --- The household, and the family it supports -----------------
            ['code' => 'household_operations', 'words' => [
                'guri', 'gurigga', 'guriga', 'aabbe', 'aabe', 'aabo', 'ayeeyo', 'hooyo', 'reer', 'ilmaha',
            ]],

            // --- The people the school pays, by name -----------------------
            ['code' => 'staff_support', 'words' => [
                'abdullahi', 'abdullhi', 'abdulhhi', 'abdalle', 'sakariye', 'siciid', 'yaxye', 'yahye',
                'liibaan', 'liiban', 'kaafiya', 'kaafiy', 'kaaafiya', 'nuuro', 'axmed', 'ahmed',
                'sheekha', 'macalin', 'macalinka', 'wiilka', 'wiilasha', 'darawal', 'askarta', 'kabo',
            ]],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Money coming in
    |----------------------------------------------------------------------
    |
    | The one kind of line in the document that is not spending. Posting a
    | receipt as an expense would charge the school for being paid.
    */
    'incoming_words' => [
        'deyn soo xarootay', 'soo xarootay', 'soo celiyay', 'soo galay', 'lacag soo gashay',
        'dakhli', 'la soo qaatay', 'income', 'received',
    ],

    /*
    | Lines with no amount that name a section rather than a cost.
    */
    'heading_words' => [
        'xiisbtii', 'xisaabtii', 'bisha hore', 'bishii hore', 'wadarta guud',
    ],

    /*
    |----------------------------------------------------------------------
    | How imported expenses are written
    |----------------------------------------------------------------------
    |
    | The document records no payment method. The ledger is a cash book, and
    | 'cash' is what the expense form itself defaults to, so that is what is
    | used — stated here rather than hidden in the importer.
    */
    'payment_method' => 'cash',

    /*
    | The marker written into every imported expense's notes. It is what makes
    | a second run of the import a no-op: before writing a line, the importer
    | looks for its own reference among the expenses already on file.
    |
    | The reference is the line's position in the document — table row and
    | column — because the ledger repeats itself legitimately ("Shidaal 50"
    | twice is two tanks of fuel), so nothing about the wording or the amount
    | can tell two entries apart.
    */
    'reference_prefix' => 'ALPHA-EXPENSE-IMPORT',

    'source_label' => 'DEYNTA BISHI AAN ISTICMAALNAY ALPHA DRIVING SCHOOL',
];
