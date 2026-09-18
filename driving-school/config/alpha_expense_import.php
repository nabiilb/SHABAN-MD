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
    | Categories
    |----------------------------------------------------------------------
    |
    | Rules are tried in order and the first match wins, so the specific sit
    | above the general. Each is matched against the whole line — the
    | description AND any words written in the amount cell beside it, because
    | "Abdullhi | 50 lesien" says in the amount cell what the money was for.
    |
    | The principle: a category describes WHAT WAS BOUGHT, not who it was
    | bought for. "Shidaal guriga" is fuel that happened to go to the house;
    | "Adeeg guri" names no purchase at all, so there the household is what is
    | left to categorise by.
    |
    | 'code' must be an expense_categories.code. Codes not already in the
    | database are listed in the dry run and created on the real import through
    | the same ExpenseCategory model the admin screens use — never a second,
    | near-duplicate spelling of a category that already exists.
    */
    'categories' => [
        // Categories this import adds, if the database does not have them yet.
        // Anything already present keeps its existing name and is reused.
        'new' => [
            'vehicle_purchase' => ['Vehicle Purchase', 'Iibka Gaariga'],
            'food_refreshments' => ['Food & Refreshments', 'Cunto & Cabitaan'],
            'certificates' => ['Certificates & Printing', 'Shahaadooyin & Daabacaad'],
            'advertising' => ['Advertising', 'Xayeysiin'],
            'transport' => ['Transport', 'Gaadiid'],
            'staff_support' => ['Staff & Personal Support', 'Taageero Shaqaale'],
            'home_support' => ['Home & General Support', 'Taageero Guri'],
        ],

        // Where an entry lands when no rule below matches it. These are listed
        // in the dry run under their own heading so they can be ruled on
        // rather than quietly filed away.
        'fallback' => 'other',

        'rules' => [
            // --- Buying a vehicle outright -------------------------------
            ['code' => 'vehicle_purchase', 'review' => true, 'words' => ['gaari iib', 'gari iib', 'iib ah', 'gaari iibsi']],

            // --- Oil, before washing: "olyo dhaqis" is an oil change -----
            ['code' => 'oil_change', 'words' => ['olyo', 'oleyo', 'oyl', 'oil']],

            // --- Washing, before anything that merely names a vehicle ----
            ['code' => 'car_wash', 'words' => ['dhaqid', 'dhaqis', 'dhaqista', 'dhaqa']],

            // --- Parts, before repair: a battery is a thing, not labour --
            ['code' => 'spare_parts', 'words' => ['batari', 'bateri', 'qaybo', 'qayb gaari']],
            ['code' => 'tires', 'words' => ['taayir', 'taayo', 'tayr']],

            // --- Fuel ----------------------------------------------------
            ['code' => 'fuel', 'words' => [
                'shidaal', 'shidal', 'shiddaal', 'shidl', 'shiidl', 'shidag', 'shidak', 'shidaa',
                'benziin', 'benzin', 'naft', 'fuel', 'petrol', 'diesel',
            ]],

            // --- Repairs and accidents -----------------------------------
            ['code' => 'vehicle_repair', 'words' => [
                'samey', 'hagaaji', 'hagajin', 'dayactir', 'shil', 'jabay', 'garaash', 'garage',
            ]],

            // --- Servicing the car, as distinct from servicing the house --
            ['code' => 'garage_service', 'words' => ['adeeg gaari', 'adeeg gari', 'adeegga gaari', 'adeegga gari', 'service gaari']],

            // --- Office ---------------------------------------------------
            ['code' => 'office', 'words' => ['xafiis', 'xafis', 'xafiska', 'xafiiska', 'xafoska', 'office']],

            // --- Internet, wherever it was installed ---------------------
            ['code' => 'internet', 'words' => ['internet', 'wifi', 'intarnet']],

            // --- Utilities: the school has an Electricity category already
            ['code' => 'electricity', 'words' => ['koronto', 'biyo', 'biil', 'biilka', 'bill', 'laydh']],

            // --- Advertising ---------------------------------------------
            ['code' => 'advertising', 'words' => ['xayeysin', 'xayeysiin', 'xayaysiin', 'bandhig', 'advert']],

            // --- Papers: certificates, licences, notary, registration ----
            ['code' => 'certificates', 'words' => [
                'shahaado', 'shahaadooyin', 'notaayo', 'notary', 'lesien', 'laysan', 'license', 'licence',
                'busniss code', 'business code', 'daabacaad', 'print',
            ]],

            // --- Food and hospitality ------------------------------------
            ['code' => 'food_refreshments', 'words' => [
                'qado', 'casho', 'quraac', 'qurac', 'shah', 'shaah', 'cabitan', 'cabbitaan',
                'cake', 'cacke', 'keeg', 'koofi', 'coffee', 'cunto', 'marti', 'biscuit',
            ]],

            // --- Transport paid for, rather than fuel bought -------------
            ['code' => 'transport', 'words' => ['bajaj', 'taksi', 'taxi', 'baabuur kiro']],

            // --- The household, and the family it supports ---------------
            ['code' => 'home_support', 'words' => [
                'guri', 'gurigga', 'guriga', 'aabbe', 'aabe', 'aabo', 'ayeeyo', 'hooyo', 'reer',
            ]],

            // --- The people the school supports by name ------------------
            // A roster, not a guess: every name below is written in the
            // ledger, and the user has confirmed each is a school expense.
            ['code' => 'staff_support', 'words' => [
                'abdullahi', 'abdullhi', 'abdulhhi', 'abdalle', 'sakariye', 'siciid', 'yaxye', 'yahye',
                'liibaan', 'liiban', 'kaafiya', 'kaafiy', 'kaaafiya', 'nuuro', 'axmed', 'ahmed',
                'sheekha', 'macalin', 'macalinka', 'wiilka', 'wiilasha', 'darawal', 'askarta', 'kabo',
            ]],

            // --- Vehicles named with no purchase beside them -------------
            // The right-hand column of the ledger is a running fuel log, and
            // most of its lines name only which car was filled: "Geyr 12/8",
            // "Otomatic hore", "Gariga cusub 10/8". Reading them as fuel is an
            // inference from the column they sit in, not something the line
            // says, so every one is flagged for review and listed on its own
            // in the dry run.
            ['code' => 'fuel', 'review' => true, 'words' => [
                'geyr', 'geer', 'otomatik', 'otomatic', 'otomotic', 'otamatic', 'atomatic', 'automatic',
                'gaariga cusub', 'gariga cusub', 'gariga cusb', 'gariga csb', 'gaari cusub', 'gaariga otomatic',
                'o.hore', 'o hore',
            ]],
        ],
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
