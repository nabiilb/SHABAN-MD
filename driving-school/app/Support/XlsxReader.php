<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads a sheet out of an .xlsx file using nothing but PHP's own zip and
 * SimpleXML extensions.
 *
 * A spreadsheet library would do more, but this import runs on a XAMPP box
 * where `composer install` is a step that can be skipped or fail. An .xlsx is
 * a zip of XML, and reading cell values out of one is small enough to own:
 * the shared-string table, inline strings, numbers, and the 1900 date serials
 * Excel writes for dates.
 */
class XlsxReader
{
    /** Excel's day 0. Day 60 does not exist — 1900 was not a leap year. */
    private const EPOCH = '1899-12-30';

    /**
     * Every row of a sheet as a plain array, cells keyed by column letter.
     *
     * @return array<int, array<string, string|float|null>> keyed by 1-based row number
     */
    public static function rows(string $path, ?string $sheetName = null): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("{$path} is not a readable .xlsx file.");
        }

        try {
            $strings = self::sharedStrings($zip);
            $dateStyles = self::dateStyles($zip);
            $sheetPath = self::sheetPath($zip, $sheetName);

            $xml = self::xml($zip, $sheetPath);

            if (! $xml) {
                throw new RuntimeException("Sheet {$sheetPath} is missing from the workbook.");
            }

            $rows = [];
            $previous = 0;

            foreach ($xml->sheetData->row as $row) {
                // Excel numbers every row and references every cell, but it is
                // not the only thing that writes these files. Export a sheet
                // from Google Sheets, save it headless through LibreOffice, or
                // write it with a script, and the r attributes can be left off
                // entirely, because position alone is unambiguous. Reading
                // those as row nought, column "", loses the whole row.
                $number = (int) $row['r'];
                $cells = [];
                $index = 0;

                foreach ($row->c as $cell) {
                    [$column, $cellRow] = self::splitReference((string) $cell['r']);

                    // An unnumbered row still knows where it is if any of its
                    // cells were referenced; only when nothing says otherwise
                    // does it fall back to following the row above.
                    if ($number === 0 && $cellRow > 0) {
                        $number = $cellRow;
                    }

                    if ($column === '') {
                        $column = self::columnName($index + 1);
                    } else {
                        $index = self::columnIndex($column) - 1;
                    }

                    $cells[$column] = self::value($cell, $strings, $dateStyles);
                    $index++;
                }

                $number = $number ?: $previous + 1;
                $previous = $number;

                $rows[$number] = $cells;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /** The sheet names in the workbook, in order. */
    public static function sheetNames(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("{$path} is not a readable .xlsx file.");
        }

        try {
            return array_keys(self::sheetMap($zip));
        } finally {
            $zip->close();
        }
    }

    /**
     * One cell exactly as the file has it, beside what rows() makes of it.
     *
     * For when a column reads as empty and the person looking at the same
     * spreadsheet can plainly see a number in it. The two answers usually
     * differ for a reason the file will admit to: a formula with no cached
     * result, a number wearing a date format, a value that is really text.
     *
     * @return array{found: bool, reference: string, type: string, style: string, formula: string|null, raw: string|null, value: string|float|null, is_date_style: bool}
     */
    public static function describeCell(string $path, ?string $sheetName, string $reference): array
    {
        [$wantColumn, $wantRow] = self::splitReference($reference);

        $absent = [
            'found' => false, 'reference' => $reference, 'type' => '', 'style' => '',
            'formula' => null, 'raw' => null, 'value' => null, 'is_date_style' => false,
        ];

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("{$path} is not a readable .xlsx file.");
        }

        try {
            $strings = self::sharedStrings($zip);
            $dateStyles = self::dateStyles($zip);
            $xml = self::xml($zip, self::sheetPath($zip, $sheetName));

            if (! $xml) {
                return $absent;
            }

            $previous = 0;

            foreach ($xml->sheetData->row as $row) {
                $number = (int) $row['r'];
                $index = 0;

                foreach ($row->c as $cell) {
                    [$column, $cellRow] = self::splitReference((string) $cell['r']);

                    if ($number === 0 && $cellRow > 0) {
                        $number = $cellRow;
                    }

                    if ($column === '') {
                        $column = self::columnName($index + 1);
                    } else {
                        $index = self::columnIndex($column) - 1;
                    }

                    $index++;

                    if (($number ?: $previous + 1) !== $wantRow || $column !== $wantColumn) {
                        continue;
                    }

                    return [
                        'found' => true,
                        'reference' => $column.$wantRow,
                        'type' => (string) $cell['t'] ?: 'n (number, implied)',
                        'style' => (string) $cell['s'],
                        'formula' => isset($cell->f) ? (string) $cell->f : null,
                        'raw' => isset($cell->v) ? (string) $cell->v : null,
                        'value' => self::value($cell, $strings, $dateStyles),
                        'is_date_style' => in_array((int) $cell['s'], $dateStyles, true),
                    ];
                }

                $previous = $number ?: $previous + 1;
            }

            return $absent;
        } finally {
            $zip->close();
        }
    }

    protected static function value(SimpleXMLElement $cell, array $strings, array $dateStyles): string|float|null
    {
        $type = (string) $cell['t'];

        if ($type === 'inlineStr') {
            return self::text($cell->is) ?: null;
        }

        if ($type === 's') {
            return $strings[(int) $cell->v] ?? null;
        }

        $raw = (string) $cell->v;

        if ($raw === '') {
            // A formula whose result was never cached. Excel always writes the
            // last calculated value beside the formula, but a writer that does
            // not calculate — a script, or a sheet exported from elsewhere —
            // writes the formula alone. Returning null there would report a
            // cell somebody filled in as empty, which is the one answer that
            // is certainly wrong, so the formula comes back as written and is
            // reported as unreadable further up.
            if (isset($cell->f)) {
                return '='.trim((string) $cell->f);
            }

            return null;
        }

        if ($type === 'str' || $type === 'e') {
            return $raw;
        }

        // A date is a number wearing a date format; only the style says so.
        if (is_numeric($raw) && in_array((int) $cell['s'], $dateStyles, true)) {
            return date('Y-m-d', strtotime(self::EPOCH.' +'.(int) $raw.' days'));
        }

        return is_numeric($raw) ? (float) $raw : $raw;
    }

    /** @return array<int, string> */
    protected static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::xml($zip, 'xl/sharedStrings.xml');

        if (! $xml) {
            return [];
        }

        $strings = [];

        foreach ($xml->si as $item) {
            $strings[] = self::text($item);
        }

        return $strings;
    }

    /**
     * The style ids whose number format is a date. Excel's built-in date
     * formats are 14-22 and 45-47; anything custom is recognised by having
     * y, d or a month token in its format code.
     */
    protected static function dateStyles(ZipArchive $zip): array
    {
        $xml = self::xml($zip, 'xl/styles.xml');

        if (! $xml) {
            return [];
        }

        $dateFormats = array_merge(range(14, 22), range(45, 47));

        foreach ($xml->numFmts->numFmt ?? [] as $format) {
            $code = (string) $format['formatCode'];

            if (preg_match('/(^|[^\\\\])[ymd]/i', $code) && ! str_contains($code, '[')) {
                $dateFormats[] = (int) $format['numFmtId'];
            }
        }

        $styles = [];
        $index = 0;

        foreach ($xml->cellXfs->xf ?? [] as $xf) {
            if (in_array((int) $xf['numFmtId'], $dateFormats, true)) {
                $styles[] = $index;
            }

            $index++;
        }

        return $styles;
    }

    protected static function sheetPath(ZipArchive $zip, ?string $sheetName): string
    {
        $sheets = self::sheetMap($zip);

        if ($sheets === []) {
            return 'xl/worksheets/sheet1.xml';
        }

        if ($sheetName === null) {
            return reset($sheets);
        }

        if (! array_key_exists($sheetName, $sheets)) {
            throw new RuntimeException("The workbook has no sheet named \"{$sheetName}\".");
        }

        return $sheets[$sheetName];
    }

    /**
     * Sheet name => path inside the archive.
     *
     * The order of sheet1.xml, sheet2.xml … does not have to match the order
     * of the tabs, so the name is resolved through the relationship id rather
     * than by counting.
     *
     * @return array<string, string>
     */
    protected static function sheetMap(ZipArchive $zip): array
    {
        $workbook = self::xml($zip, 'xl/workbook.xml');
        $relationships = self::xml($zip, 'xl/_rels/workbook.xml.rels');

        if (! $workbook) {
            return [];
        }

        $targets = [];

        foreach ($relationships->Relationship ?? [] as $relationship) {
            $targets[(string) $relationship['Id']] = ltrim((string) $relationship['Target'], '/');
        }

        $sheets = [];

        foreach ($workbook->sheets->sheet as $sheet) {
            $id = (string) $sheet->attributes('r', true)->id;
            $target = $targets[$id] ?? null;

            $sheets[(string) $sheet['name']] = $target
                ? (str_starts_with($target, 'xl/') ? $target : 'xl/'.$target)
                : 'xl/worksheets/sheet'.(count($sheets) + 1).'.xml';
        }

        return $sheets;
    }

    /** Concatenates the text runs inside a shared-string or inline-string node. */
    protected static function text(?SimpleXMLElement $node): string
    {
        if (! $node) {
            return '';
        }

        if (isset($node->t)) {
            return (string) $node->t;
        }

        $text = '';

        foreach ($node->r ?? [] as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /** "B12" => ["B", 12]. Lower case is legal in the file, if unusual. */
    protected static function splitReference(string $reference): array
    {
        preg_match('/^([A-Za-z]+)(\d+)$/', trim($reference), $matches);

        return [strtoupper($matches[1] ?? ''), (int) ($matches[2] ?? 0)];
    }

    /** 1 => "A", 27 => "AA". */
    protected static function columnName(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $index--;
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /** "A" => 1, "AA" => 27. */
    protected static function columnIndex(string $name): int
    {
        $index = 0;

        foreach (str_split(strtoupper($name)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }

    protected static function xml(ZipArchive $zip, string $path): ?SimpleXMLElement
    {
        $contents = $zip->getFromName($path);

        return $contents === false ? null : new SimpleXMLElement($contents);
    }
}
