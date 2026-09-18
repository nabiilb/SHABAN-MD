<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the text out of a .docx using nothing but PHP's own zip and SimpleXML
 * extensions, in the same spirit as XlsxReader beside it.
 *
 * A .docx is a zip holding one XML document, and the two things a ledger kept
 * in Word has to offer — the loose paragraphs and the table cells, in the
 * order they were typed — are a short walk of that XML. A full Word toolkit
 * would be a dependency to install, patch and audit on a shared host for no
 * gain here.
 */
class DocxReader
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * The document's loose paragraphs — everything outside a table — in order.
     *
     * @return array<int, string>
     */
    public static function paragraphs(string $path): array
    {
        return self::read($path)['paragraphs'];
    }

    /**
     * Every table row in the document, numbered from 1 and running straight
     * through all of its tables, with the cells left as written.
     *
     * The numbering is what an import reference is built from, so it counts
     * blank rows too: skipping them would renumber everything below the first
     * empty line somebody deletes.
     *
     * @return array<int, array<int, string>> keyed by 1-based row number
     */
    public static function rows(string $path): array
    {
        return self::read($path)['rows'];
    }

    /**
     * @return array{paragraphs: array<int, string>, rows: array<int, array<int, string>>}
     */
    public static function read(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("{$path} is not a readable .docx file.");
        }

        try {
            $xml = $zip->getFromName('word/document.xml');

            if ($xml === false) {
                throw new RuntimeException("{$path} has no word/document.xml — it is not a Word document.");
            }
        } finally {
            $zip->close();
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new SimpleXMLElement($xml);
        } catch (\Exception $e) {
            throw new RuntimeException("The Word document at {$path} could not be parsed: ".$e->getMessage());
        } finally {
            libxml_use_internal_errors($previous);
        }

        $document->registerXPathNamespace('w', self::NS);

        $paragraphs = [];
        $rows = [];
        $rowNumber = 0;

        // Loose paragraphs are the ones with no table ancestor. Asking XPath
        // for that is cheaper and steadier than walking the body by hand,
        // because Word nests paragraphs inside shapes and content controls at
        // depths that differ between the programs that wrote the file.
        foreach ($document->xpath('//w:body//w:p[not(ancestor::w:tbl)]') ?: [] as $paragraph) {
            $paragraphs[] = self::text($paragraph);
        }

        foreach ($document->xpath('//w:tbl/w:tr') ?: [] as $row) {
            $cells = [];

            foreach ($row->xpath('w:tc') ?: [] as $index => $cell) {
                $cells[$index + 1] = self::cellText($cell);
            }

            $rows[++$rowNumber] = $cells;
        }

        return ['paragraphs' => $paragraphs, 'rows' => $rows];
    }

    /**
     * A table cell's text.
     *
     * A cell holds paragraphs, and the ledger uses a second one where a figure
     * and the date beside it were typed on two lines: "27" over "12/8". Joined
     * with nothing between them that reads as 2712/8, so the paragraph break
     * becomes the space it looks like on the page.
     */
    private static function cellText(SimpleXMLElement $cell): string
    {
        $cell->registerXPathNamespace('w', self::NS);

        $paragraphs = array_map(
            fn (SimpleXMLElement $paragraph) => self::text($paragraph),
            $cell->xpath('.//w:p') ?: [],
        );

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $paragraphs)) ?? '');
    }

    /**
     * Every run of text under an element, with Word's tabs and line breaks
     * flattened to single spaces.
     *
     * A cell's wording is split across as many <w:t> runs as it took changes
     * of font, so "Shidaal otomatic" can arrive in four pieces; they are
     * joined with nothing between them, exactly as Word renders them.
     */
    private static function text(SimpleXMLElement $element): string
    {
        $element->registerXPathNamespace('w', self::NS);

        $parts = [];

        foreach ($element->xpath('.//w:t | .//w:tab | .//w:br | .//w:cr') ?: [] as $node) {
            $parts[] = $node->getName() === 't' ? (string) $node : ' ';
        }

        return trim(preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '');
    }
}
