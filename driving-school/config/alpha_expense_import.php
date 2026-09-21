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
    | What may be imported
    |----------------------------------------------------------------------
    |
    | A safe list, not a filter. A line is imported only when its own wording
    | says what the money bought and that purchase is the school's; everything
    | else is held for review. The school confirmed in an earlier round that
    | the informal-looking lines are its spending, and has since asked for the
    | stricter reading: informal wording is no longer enough on its own,
    | because "Aabbe 15" does not say what was bought and the ledger cannot be
    | asked.
    |
    | Rules are tried in order, first match wins, and each is matched against
    | the description AND any words in the amount cell beside it.
    |
    | 'needs' names a second word that must also appear. It is how "dhaqis"
    | becomes a car wash only when a vehicle is named beside it, and stays a
    | question when it is not.
    */
    'categories' => [
        'new' => [
            'certificates' => ['Certificates & Printing', 'Shahaadooyin & Daabacaad'],
            'advertising' => ['Advertising', 'Xayeysiin'],
            'other_business' => ['Other Business Expense', 'Kharash Ganacsi Kale'],
        ],

        'vehicle_words' => [
            'gaari', 'gari', 'gaariga', 'gariga', 'gaarigga', 'baabuur', 'gaadiid',
            'otomatic', 'otomatik', 'otomotic', 'otamatic', 'atomatic', 'automatic', 'geyr',
        ],

        'rules' => [
            // --- Fuel -----------------------------------------------------
            ['code' => 'fuel', 'words' => [
                'shidaal', 'shidal', 'shiddaal', 'shidl', 'shiidl', 'shidag', 'shidak', 'shidaa',
                'benziin', 'benzin', 'naft', 'fuel', 'petrol', 'diesel',
            ]],

            // --- Oil, before washing: "olyo dhaqis" is an oil change ------
            ['code' => 'oil_change', 'words' => ['olyo', 'oleyo', 'oyl', 'oil']],

            // --- Washing, but only where a vehicle is named ---------------
            ['code' => 'car_wash', 'needs' => 'vehicle', 'words' => ['dhaqid', 'dhaqis', 'dhaqista', 'dhaqa']],

            // --- Parts ----------------------------------------------------
            ['code' => 'spare_parts', 'words' => ['batari', 'bateri', 'qaybo gaari']],
            ['code' => 'tires', 'words' => ['taayir', 'taayirro']],

            // --- Repairs, which name the vehicle or the damage ------------
            ['code' => 'vehicle_repair', 'needs' => 'vehicle', 'words' => ['samey', 'hagaaji', 'hagajin', 'dayactir', 'shil', 'jabay']],
            ['code' => 'vehicle_repair', 'words' => ['garaash', 'garage', 'dayactirka']],

            // --- Servicing the car, as distinct from servicing a house ----
            ['code' => 'garage_service', 'words' => ['adeeg gaari', 'adeeg gari', 'adeegga gaari', 'adeegga gari', 'adeeg gaarigga']],

            // --- The office -----------------------------------------------
            ['code' => 'office', 'words' => ['xafiis', 'xafis', 'xafiska', 'xafiiska', 'xafoska', 'office']],

            // --- Advertising ----------------------------------------------
            ['code' => 'advertising', 'words' => ['xayeysin', 'xayeysiin', 'xayaysiin', 'bandhig', 'advert']],

            // --- Papers the school has to hold ----------------------------
            ['code' => 'certificates', 'words' => [
                'shahaado', 'shahaadooyin', 'notaayo', 'notary', 'lesien', 'laysan', 'license', 'licence',
                'busniss code', 'business code', 'daabacaad',
            ]],
        ],

        /*
        | Lines that plainly describe a school cost but fit no rule above.
        | Deliberately short: this is the last door before review, not a
        | catch-all, and nothing reaches it on the strength of an amount.
        */
        'other_business' => ['kiro xafiis', 'kirada xafiiska', 'shaqaale mushahar'],
    ],

    /*
    |----------------------------------------------------------------------
    | Lines that are held back
    |----------------------------------------------------------------------
    |
    | Money that came in rather than went out, and the words that make a line
    | a question rather than an expense: a person's name, a household, a
    | vehicle bought outright. None of these is refused as untrue — the school
    | says they are all its spending — they are refused as unreadable. The
    | ledger does not say what was bought, and an expense ledger that guesses
    | is worse than one that asks.
    */
    'incoming_words' => [
        'deyn soo xarootay', 'soo xarootay', 'soo celiyay', 'soo galay', 'lacag soo gashay',
        'dakhli', 'la soo qaatay', 'income', 'received',
    ],

    'review_words' => [
        // Buying a vehicle is capital, not an operating expense, and is not
        // something to post from a line of a notebook.
        'gaari iib', 'gari iib', 'iib ah', 'gaari iibsi',

        // A household, and the family it supports.
        'guri', 'gurigga', 'guriga', 'aabbe', 'aabe', 'aabo', 'ayeeyo', 'hooyo', 'reer', 'ilmaha',

        // People, by name, as the ledger writes them.
        'abdullahi', 'abdullhi', 'abdulhhi', 'abdalle', 'sakariye', 'siciid', 'yaxye', 'yahye',
        'liibaan', 'liiban', 'kaafiya', 'kaafiy', 'kaaafiya', 'nuuro', 'axmed', 'ahmed',
        'sheekha', 'macalin', 'macalinka', 'wiilka', 'wiilasha', 'darawal', 'askarta', 'marti',

        // Things bought for a person rather than the school.
        'kabo', 'qamiis', 'jamacad', 'qado', 'casho', 'quraac', 'qurac', 'shah', 'shaah',
        'cabitan', 'cabbitaan', 'cake', 'cacke', 'keeg', 'koofi', 'coffee', 'cunto', 'bajaj',
    ],

    /*
    | Lines with no amount that read as a heading rather than a lost figure.
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
