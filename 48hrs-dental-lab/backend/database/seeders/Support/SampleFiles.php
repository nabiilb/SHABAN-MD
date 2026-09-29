<?php

namespace Database\Seeders\Support;

/** Content for the demo attachments (packages/shared/src/demo-files.ts), so seeded files open like real uploads. */
final class SampleFiles
{
    public static function pdf(array $lines): string
    {
        $esc = fn (string $s) => preg_replace('/[\\\\()]/', '\\\\$0', $s);
        $text = implode("\n", array_map(fn ($l, $i) => 'BT /F1 '.($i === 0 ? 16 : 11).' Tf 60 '.(760 - $i * 22).' Td ('.$esc($l).') Tj ET', $lines, array_keys($lines)));
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($text)." >>\nstream\n{$text}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n{$o}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf('%010d', $o)." 00000 n \n";
        }

        return $out."trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    public static function prescriptionPdf(string $caseNumber): string
    {
        return self::pdf(['48HRS Dental Lab - Prescription', "Case {$caseNumber}", 'Sample document generated for demo data.', 'Files you upload yourself are stored and returned byte-for-byte.']);
    }

    /** ASCII STL of a small cube. */
    public static function stl(string $name): string
    {
        $v = [[0, 0, 0], [10, 0, 0], [10, 10, 0], [0, 10, 0], [0, 0, 10], [10, 0, 10], [10, 10, 10], [0, 10, 10]];
        $f = [[0, 2, 1], [0, 3, 2], [4, 5, 6], [4, 6, 7], [0, 1, 5], [0, 5, 4], [1, 2, 6], [1, 6, 5], [2, 3, 7], [2, 7, 6], [3, 0, 4], [3, 4, 7]];
        $solid = preg_replace('/\W+/', '_', $name);
        $facets = implode("\n", array_map(fn ($t) => "  facet normal 0 0 0\n    outer loop\n".implode("\n", array_map(fn ($i) => '      vertex '.implode(' ', $v[$i]), $t))."\n    endloop\n  endfacet", $f));

        return "solid {$solid}\n{$facets}\nendsolid {$solid}\n";
    }

    /** A 480×320 navy placeholder photo with a lighter arch band — a real PNG. */
    public static function png(): string
    {
        $w = 480;
        $h = 320;
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\0";
            for ($x = 0; $x < $w; $x++) {
                $dx = ($x - $w / 2) / 170;
                $dy = ($y - $h * 0.62) / 110;
                $r = $dx * $dx + $dy * $dy;
                $arch = $r > 0.82 && $r < 1 && $y < $h * 0.62;
                $raw .= $arch ? "\x2f\x6f\xc4" : "\x0a\x14\x24";
            }
        }
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
    }
}
