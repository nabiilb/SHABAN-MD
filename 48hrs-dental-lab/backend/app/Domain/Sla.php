<?php

namespace App\Domain;

/**
 * The 48-hour clock (packages/shared/src/sla.ts): states derived purely from
 * stored timestamps and a server-side "now". Cases are arrays with ISO strings.
 */
final class Sla
{
    public const LABELS = [
        'not_started' => 'Not started',
        'on_track' => 'On track',
        'at_risk' => 'At risk',
        'critical' => 'Critical',
        'overdue' => 'Overdue',
        'met' => 'Delivered on time',
        'late' => 'Delivered late',
        'stopped' => 'Stopped',
    ];

    private const STOPPED = ['cancelled', 'rejected'];

    /** @return array{slaHours: int|float, atRiskHours: int|float, criticalHours: int|float} */
    public static function config(array $settings): array
    {
        return ['slaHours' => $settings['slaHours'], 'atRiskHours' => $settings['atRiskHours'], 'criticalHours' => $settings['criticalHours']];
    }

    /** due_at = received_at + SLA hours. */
    public static function computeDueAt(int $receivedMs, int|float $slaHours): int
    {
        return (int) ($receivedMs + $slaHours * Dates::HOUR_MS);
    }

    /**
     * @param  array{status: string, receivedAt?: ?string, dueAt?: ?string, deliveredAt?: ?string}  $c
     * @return array{state: string, label: string, remainingMs: int|float|null, progress: float|int, turnaroundMs: int|null, dueAt: ?int}
     */
    public static function info(array $c, int $now, array $config): array
    {
        $received = Dates::ms($c['receivedAt'] ?? null);
        $due = ($c['dueAt'] ?? null) ? Dates::ms($c['dueAt']) : ($received !== null ? $received + $config['slaHours'] * Dates::HOUR_MS : null);
        $stopped = in_array($c['status'], self::STOPPED, true);

        if ($received === null || $due === null) {
            $state = $stopped ? 'stopped' : 'not_started';

            return ['state' => $state, 'label' => self::LABELS[$state], 'remainingMs' => null, 'progress' => 0, 'turnaroundMs' => null, 'dueAt' => $due];
        }

        $window = max($due - $received, 1);

        if ($c['deliveredAt'] ?? null) {
            $delivered = Dates::ms($c['deliveredAt']);
            $state = $delivered <= $due ? 'met' : 'late';

            return ['state' => $state, 'label' => self::LABELS[$state], 'remainingMs' => $due - $delivered, 'progress' => min(1, ($delivered - $received) / $window), 'turnaroundMs' => $delivered - $received, 'dueAt' => $due];
        }

        if ($stopped) {
            return ['state' => 'stopped', 'label' => self::LABELS['stopped'], 'remainingMs' => null, 'progress' => 0, 'turnaroundMs' => null, 'dueAt' => $due];
        }

        $remaining = $due - $now;
        $progress = min(1, max(0, ($now - $received) / $window));
        $state = 'on_track';
        if ($remaining <= 0) {
            $state = 'overdue';
        } elseif ($remaining <= $config['criticalHours'] * Dates::HOUR_MS) {
            $state = 'critical';
        } elseif ($remaining <= $config['atRiskHours'] * Dates::HOUR_MS) {
            $state = 'at_risk';
        }

        return ['state' => $state, 'label' => self::LABELS[$state], 'remainingMs' => $remaining, 'progress' => $progress, 'turnaroundMs' => null, 'dueAt' => $due];
    }

    public static function state(array $c, int $now, array $config): string
    {
        return self::info($c, $now, $config)['state'];
    }

    /** true = delivered by due_at, false = late, null = not delivered / no deadline (uses the default config like the web app). */
    public static function deliveredOnTime(array $c): ?bool
    {
        $s = self::info($c, Dates::nowMs(), self::defaultConfig())['state'];

        return $s === 'met' ? true : ($s === 'late' ? false : null);
    }

    /** Share of delivered cases that met their deadline, or null when none were delivered. */
    public static function onTimeRate(array $cases): float|int|null
    {
        $measured = array_values(array_filter(array_map(fn ($c) => self::deliveredOnTime($c), $cases), fn ($x) => $x !== null));

        return $measured ? count(array_filter($measured)) / count($measured) : null;
    }

    public static function defaultConfig(): array
    {
        return ['slaHours' => 48, 'atRiskHours' => 12, 'criticalHours' => 4];
    }

    /** The five business outcomes (CaseSlaService in the Node API). */
    public static function outcome(array $c, int $now, array $config): string
    {
        return match ($s = self::state($c, $now, $config)) {
            'on_track' => 'on_time',
            'at_risk', 'critical' => 'at_risk',
            'overdue' => 'overdue',
            'met' => 'completed_on_time',
            'late' => 'completed_late',
            default => $s,
        };
    }
}
