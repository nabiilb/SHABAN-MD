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

            foreach ($xml->sheetData->row as $row) {
                $number = (int) $row['r'];
                $cells = [];

                foreach ($row->c as $cell) {
                    [$column] = self::splitReference((string) $cell['r']);
                    $cells[$column] = self::value($cell, $strings, $dateStyles);
                }

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

    /** "B12" => ["B", 12] */
    protected static function splitReference(string $reference): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', $reference, $matches);

        return [$matches[1] ?? '', (int) ($matches[2] ?? 0)];
    }

    protected static function xml(ZipArchive $zip, string $path): ?SimpleXMLElement
    {
        $contents = $zip->getFromName($path);

        return $contents === false ? null : new SimpleXMLElement($contents);
    }
}
