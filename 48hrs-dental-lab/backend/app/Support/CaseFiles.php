<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The private "cases" disk. Files are addressed by server-generated keys only;
 * user-supplied names never reach the filesystem.
 */
final class CaseFiles
{
    public static function disk(): Filesystem
    {
        return Storage::disk('cases');
    }

    public static function newKey(string $extension, ?\DateTimeInterface $at = null): string
    {
        $at ??= now();
        $ext = substr(preg_replace('/[^a-z0-9]/', '', strtolower($extension)), 0, 8) ?: 'bin';

        return $at->format('Y/m').'/'.Str::random(24).'.'.$ext;
    }

    public static function isValidKey(string $key): bool
    {
        return (bool) preg_match('#^[\w./-]+$#', $key) && ! str_contains($key, '..');
    }

    public static function put(string $key, string $content): void
    {
        self::disk()->put($key, $content);
    }

    public static function delete(string $key): void
    {
        if (self::isValidKey($key)) {
            self::disk()->delete($key);
        }
    }

    public static function exists(string $key): bool
    {
        return self::isValidKey($key) && self::disk()->exists($key);
    }

    /** Removes every stored object (demo re-seed only — rows are wiped in the same step). */
    public static function clear(): void
    {
        $disk = self::disk();
        foreach ($disk->directories() as $dir) {
            $disk->deleteDirectory($dir);
        }
        foreach ($disk->files() as $file) {
            $disk->delete($file);
        }
    }
}
