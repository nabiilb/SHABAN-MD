<?php

/*
|--------------------------------------------------------------------------
| Alpha School register import
|--------------------------------------------------------------------------
|
| Every judgement the importer makes about the school's spreadsheet lives
| here, in the open, rather than inside the parser. A correction made in this
| file is a correction somebody can read, argue with and revert; one buried in
| a regular expression is not.
|
| Nothing here is applied silently: the dry run lists every row that a setting
| below changed, so `alpha-school:import --dry-run` always shows the file as it
| will actually be read.
|
*/

return [

    /*
    | Where the register lives, unless --file says otherwise.
    */
    'file' => storage_path('app/imports/ALPHA SCHOOL.xlsx'),

    'sheet' => 'Sheet1',

    /*
    |----------------------------------------------------------------------
    | Dates Excel stored as real dates
    |----------------------------------------------------------------------
    |
    | The register is written day-first — 14/7/2026, 04.08.2026 — but a
    | handful of cells were typed in a way Excel recognised, and it read them
    | month-first. "6/7/2026" became 6 June rather than 6 July.
    |
    | With this on, a stored date whose day is 12 or less is read the way the
    | rest of the column is written, and every affected row is listed in the
    | dry run. Turn it off to take those cells exactly as Excel has them.
    |
    | The evidence for it is in the sequence: rows 2-6 read day-first give
    | 6, 6, 7, 7 and 12 July, immediately before the 14 July rows that follow;
    | read as stored they give 7 June, 7 June, 7 July, 7 July and 7 December,
    | which puts December in the middle of July.
    */
    'read_stored_dates_day_first' => true,

    /*
    |----------------------------------------------------------------------
    | Explicit per-row date corrections
    |----------------------------------------------------------------------
    |
    | Keyed by the Excel row number exactly as the dry run prints it, with the
    | date to use in Y-m-d. Use this for the rows the register itself got
    | wrong — a 2027 in a 2026 book — where a person has decided what the date
    | should have been. The importer never invents these.
    |
    | Every entry is reported under "Dates corrected by config" when the
    | importer runs, so the file and the correction are always read together.
    |
    |   45 => '2026-07-29',
    |   99 => '2026-08-24',
    */
    'date_corrections' => [
        //
    ],

    /*
    |----------------------------------------------------------------------
    | Rows to leave out entirely
    |----------------------------------------------------------------------
    |
    | Excel row numbers the office has decided are not students. Section
    | totals and rows with no phone are already skipped without help; this is
    | for anything else somebody rules out by hand.
    */
    'skip_rows' => [
        //
    ],

    /*
    |----------------------------------------------------------------------
    | The payment method imported payments are recorded under
    |----------------------------------------------------------------------
    |
    | The register does not say how anybody paid. This must be one of the
    | values the student_payments column allows.
    */
    'payment_method' => 'cash',
];
