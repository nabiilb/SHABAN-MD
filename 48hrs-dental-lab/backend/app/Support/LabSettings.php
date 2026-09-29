<?php

namespace App\Support;

use App\Domain\Catalog;
use App\Domain\Sla;
use App\Models\Setting;

/** The editable lab settings (settings.key = "lab"), defaults merged in so a newly added setting always has a value. */
final class LabSettings
{
    private const KEY = 'lab';

    public static function get(): array
    {
        $row = Setting::find(self::KEY);

        return [...Catalog::DEFAULT_LAB_SETTINGS, ...($row?->value ?? [])];
    }

    public static function put(array $settings): array
    {
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $settings]);

        return $settings;
    }

    public static function sla(): array
    {
        return Sla::config(self::get());
    }
}
