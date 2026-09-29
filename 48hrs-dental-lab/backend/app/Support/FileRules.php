<?php

namespace App\Support;

/**
 * Upload rules beyond extension and size: the file's leading bytes must match
 * its extension, so a renamed executable or HTML page is rejected even with an
 * allowed extension. The client's declared MIME type is never trusted.
 */
final class FileRules
{
    /** MIME types we answer with on download, keyed by extension (never the client's claim). */
    public const DOWNLOAD_MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'stl' => 'model/stl', 'ply' => 'application/octet-stream', 'obj' => 'model/obj', 'dcm' => 'application/dicom',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const SIGNATURES = [
        'jpg' => ["\xff\xd8\xff"],
        'jpeg' => ["\xff\xd8\xff"],
        'png' => ["\x89PNG\r\n\x1a\n"],
        'pdf' => ['%PDF'],
        // Word 97 (OLE) and Office Open XML (zip).
        'doc' => ["\xd0\xcf\x11\xe0"],
        'docx' => ["PK\x03\x04"],
    ];

    public static function mimeFor(string $extension): string
    {
        return self::DOWNLOAD_MIME[$extension] ?? 'application/octet-stream';
    }

    /** An error message, or null when the content is acceptable for the extension. */
    public static function checkContent(string $extension, string $head, ?int $size = null): ?string
    {
        if (self::looksExecutableOrMarkup($head)) {
            return 'This file type is not accepted.';
        }
        if ($extension === 'stl' && ! self::looksLikeStl($head, $size ?? strlen($head))) {
            return "The file content does not match its .{$extension} extension.";
        }
        if ($extension === 'webp' && ! (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP')) {
            return "The file content does not match its .{$extension} extension.";
        }
        foreach (self::SIGNATURES[$extension] ?? [] as $signature) {
            if (! str_starts_with($head, $signature)) {
                return "The file content does not match its .{$extension} extension.";
            }
        }

        return null;
    }

    /**
     * ASCII STL starts with "solid"; binary STL is an 80-byte header, a triangle
     * count and 50 bytes per triangle (some exporters pad the end, so the file
     * may be longer, never shorter).
     */
    private static function looksLikeStl(string $head, int $size): bool
    {
        if (strtolower(substr(ltrim($head), 0, 5)) === 'solid') {
            return true;
        }
        if (strlen($head) < 84) {
            return false;
        }
        $triangles = unpack('V', substr($head, 80, 4))[1];

        return $triangles > 0 && 84 + 50 * $triangles <= $size;
    }

    /** Content that must never be stored, whatever the extension says. */
    private static function looksExecutableOrMarkup(string $head): bool
    {
        $text = strtolower(ltrim($head));

        return str_starts_with($head, 'MZ') // PE / DOS executable
            || str_starts_with($head, "\x7fELF")
            || str_starts_with($text, '<!doctype html')
            || str_starts_with($text, '<html')
            || str_starts_with($text, '<script')
            || str_starts_with($text, '<svg')
            || str_starts_with($text, '<?php')
            || str_starts_with($text, '#!');
    }

    /** Display name only: no directories, no control characters, bounded length. */
    public static function safeName(string $original): string
    {
        $printable = preg_replace('/[\x00-\x1f\x7f"]/u', '', $original) ?? '';
        $name = trim(basename(str_replace('\\', '/', $printable)));

        return mb_substr($name !== '' ? $name : 'upload', 0, 180);
    }
}
