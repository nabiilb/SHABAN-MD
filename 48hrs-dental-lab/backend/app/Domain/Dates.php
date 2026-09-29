<?php

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Instants are epoch milliseconds (int) or ISO-8601 UTC strings, as in the web app.
 * A "day" is a YYYY-MM-DD string in the lab's time zone (LAB_TIMEZONE).
 */
final class Dates
{
    public const HOUR_MS = 3_600_000;

    public const DAY_MS = 86_400_000;

    public const DAY_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /** @var array<string, DateTimeZone> */
    private static array $zones = [];

    public static function zone(?string $tz = null): DateTimeZone
    {
        $tz ??= config('lab.timezone', 'UTC');

        return self::$zones[$tz] ??= new DateTimeZone($tz);
    }

    public static function nowMs(): int
    {
        return (int) now()->format('Uv');
    }

    /** ISO string / DateTimeInterface / ms → ms. */
    public static function ms(string|int|\DateTimeInterface|null $v): ?int
    {
        return match (true) {
            $v === null => null,
            is_int($v) => $v,
            $v instanceof \DateTimeInterface => (int) $v->format('Uv'),
            default => (int) (new DateTimeImmutable($v))->format('Uv'),
        };
    }

    public static function fromMs(int $ms): DateTimeImmutable
    {
        $sec = intdiv($ms, 1000) - ($ms % 1000 < 0 ? 1 : 0);
        $milli = $ms - $sec * 1000;

        return (new DateTimeImmutable('@'.$sec))->modify("+{$milli} milliseconds")->setTimezone(new DateTimeZone('UTC'));
    }

    /** Date.prototype.toISOString(): 2026-06-15T09:00:00.000Z */
    public static function iso(string|int|\DateTimeInterface|null $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $d = is_int($v) ? self::fromMs($v) : (is_string($v) ? new DateTimeImmutable($v) : DateTimeImmutable::createFromInterface($v));

        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** YYYY-MM-DD of an instant in a time zone (the lab's by default). */
    public static function dayIn(string|int|\DateTimeInterface $v, ?string $tz = null): string
    {
        $ms = self::ms($v);

        return self::fromMs($ms)->setTimezone(self::zone($tz))->format('Y-m-d');
    }

    /** Calendar arithmetic on YYYY-MM-DD strings (time-zone free). */
    public static function shiftDay(string $day, int $days): string
    {
        return (new DateTimeImmutable($day.' 00:00:00', new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '').$days.' days')->format('Y-m-d');
    }

    /** YYYY-MM for the month `months` before/after the month of `day`. */
    public static function shiftMonth(string $day, int $months): string
    {
        [$y, $m] = array_map('intval', explode('-', $day));
        $total = $y * 12 + ($m - 1) + $months;
        $year = intdiv($total, 12) - ($total % 12 < 0 ? 1 : 0);
        $month = $total - $year * 12 + 1;

        return sprintf('%04d-%02d', $year, $month);
    }

    public static function isDay(mixed $v): bool
    {
        if (! is_string($v) || ! preg_match(self::DAY_PATTERN, $v)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));

        return checkdate($m, $d, $y);
    }

    /** The instant (ms) a calendar day starts in a time zone. */
    public static function startOfDay(string $day, ?string $tz = null): int
    {
        return self::ms(new DateTimeImmutable($day.' 00:00:00', self::zone($tz)));
    }

    /** First instant of the following day — an exclusive upper bound. */
    public static function endOfDayExclusive(string $day, ?string $tz = null): int
    {
        return self::startOfDay(self::shiftDay($day, 1), $tz);
    }

    /** Lab-zone day bounds as UTC DateTimes for SQL. */
    public static function dayStartUtc(string $day): DateTimeImmutable
    {
        return self::fromMs(self::startOfDay($day));
    }

    public static function dayEndUtc(string $day): DateTimeImmutable
    {
        return self::fromMs(self::endOfDayExclusive($day));
    }

    public static function labYear(?int $nowMs = null): int
    {
        return (int) substr(self::dayIn($nowMs ?? self::nowMs()), 0, 4);
    }

    /** "Jun 26", "Sept 26" — the en-GB short month label used by charts. */
    public static function monthLabel(string $yyyyMm): string
    {
        static $names = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];
        [$y, $m] = array_map('intval', explode('-', $yyyyMm));

        return $names[$m].' '.substr((string) $y, -2);
    }
}
