<?php

namespace App\Domain;

/**
 * Dashboard, report and directory aggregations (packages/shared/src/analytics.ts).
 * Pure functions over already-scoped case arrays (API shape, ISO timestamps), so
 * the numbers match what the web app computes in its mock mode exactly.
 */
final class Analytics
{
    public const DASHBOARD_PERIODS = ['today', '7d', '30d', 'month'];

    private const MAX_DAILY_POINTS = 120;

    public static function parsePeriod(mixed $v): string
    {
        return in_array($v, self::DASHBOARD_PERIODS, true) ? $v : '30d';
    }

    public static function periodStartDay(string $period, string $today): string
    {
        if ($period === 'month') {
            return substr($today, 0, 8).'01';
        }

        return Dates::shiftDay($today, match ($period) { 'today' => 0, '7d' => -6, default => -29 });
    }

    /** Earliest day the dashboard needs case rows for: the period start or the 14-day chart, whichever is older. */
    public static function dashboardWindowStart(string $period, string $today): string
    {
        $p = self::periodStartDay($period, $today);
        $chart = Dates::shiftDay($today, -13);

        return $p < $chart ? $p : $chart;
    }

    public static function revenueWindowMonth(string $today): string
    {
        return Dates::shiftMonth($today, -5);
    }

    public static function hoursBetween(?string $a, ?string $b): float|int|null
    {
        return $a && $b ? (Dates::ms($b) - Dates::ms($a)) / Dates::HOUR_MS : null;
    }

    private static function day(?string $v, string $tz): ?string
    {
        return $v ? Dates::dayIn($v, $tz) : null;
    }

    /**
     * @param  array{now: int, period: string, statusCounts: array<string, int>, inLab: list<array>, recent: list<array>, finance: ?array}  $i
     */
    public static function buildDashboard(array $i, array $sla, string $tz): array
    {
        $now = $i['now'];
        $today = Dates::dayIn($now, $tz);
        $periodStart = self::periodStartDay($i['period'], $today);
        $inPeriod = fn (?string $v) => $v !== null && $v !== '' && Dates::dayIn($v, $tz) >= $periodStart;
        $n = fn (string ...$s) => array_sum(array_map(fn ($st) => $i['statusCounts'][$st] ?? 0, $s));
        $slaRows = array_map(fn ($c) => ['c' => $c, 'info' => Sla::info($c, $now, $sla)], $i['inLab']);
        $recentDone = array_values(array_filter($i['recent'], fn ($c) => ! empty($c['receivedAt']) && $inPeriod($c['deliveredAt'] ?? null)));

        $last14 = [];
        for ($k = 0; $k < 14; $k++) {
            $day = Dates::shiftDay($today, $k - 13);
            $last14[] = [
                'date' => $day,
                'received' => count(array_filter($i['recent'], fn ($c) => ! empty($c['receivedAt']) && Dates::dayIn($c['receivedAt'], $tz) === $day)),
                'delivered' => count(array_filter($i['recent'], fn ($c) => ! empty($c['deliveredAt']) && Dates::dayIn($c['deliveredAt'], $tz) === $day)),
            ];
        }

        $revenueByMonth = null;
        $f = $i['finance'];
        if ($f !== null) {
            $revenueByMonth = [];
            for ($k = 0; $k < 6; $k++) {
                $m = Dates::shiftMonth($today, $k - 5);
                $revenueByMonth[] = [
                    'month' => Dates::monthLabel($m),
                    'invoiced' => Num::round1(array_sum(array_map(fn ($x) => substr(Dates::dayIn($x['issuedAt'], $tz), 0, 7) === $m ? $x['total'] : 0, $f['invoices']))),
                    'collected' => Num::round1(array_sum(array_map(fn ($p) => substr(Dates::dayIn($p['paidAt'], $tz), 0, 7) === $m ? $p['amount'] : 0, $f['payments']))),
                ];
            }
        }

        $state = fn (string ...$states) => count(array_filter($slaRows, fn ($r) => in_array($r['info']['state'], $states, true)));

        return [
            'period' => $i['period'],
            'periodStart' => $periodStart,
            'activeCases' => count($i['inLab']),
            'newCases' => count(array_filter($i['recent'], fn ($c) => $inPeriod($c['receivedAt'] ?? $c['submittedAt'] ?? $c['createdAt']) && $c['status'] !== 'rejected')),
            'dueToday' => count(array_filter($slaRows, fn ($r) => ! empty($r['c']['dueAt']) && Dates::dayIn($r['c']['dueAt'], $tz) === $today && $r['info']['state'] !== 'overdue')),
            'overdue' => $state('overdue'),
            'completed' => $n(...Workflow::DONE_STATUSES),
            'completedInPeriod' => count($recentDone),
            'inProduction' => $n(...Workflow::PRODUCTION_STATUSES),
            'pendingQc' => $n('quality_control'),
            'readyForDelivery' => $n('ready', 'out_for_delivery'),
            'awaitingAcceptance' => $n('submitted'),
            'revenue' => $f !== null ? Num::round1(array_sum(array_map(fn ($x) => $inPeriod($x['issuedAt']) ? $x['total'] : 0, $f['invoices']))) : null,
            'collected' => $f !== null ? Num::round1(array_sum(array_map(fn ($p) => $inPeriod($p['paidAt']) ? $p['amount'] : 0, $f['payments']))) : null,
            'outstanding' => $f !== null ? Num::round1($f['outstanding']) : null,
            'performance' => [
                'onTime' => $state('on_track'),
                'atRisk' => $state('at_risk', 'critical'),
                'overdue' => $state('overdue'),
                'onTimeRate' => Sla::onTimeRate($recentDone),
                'avgCompletionHours' => Num::average(array_map(fn ($c) => self::hoursBetween($c['receivedAt'], $c['deliveredAt']), $recentDone)),
            ],
            'last14Days' => $last14,
            'statusBreakdown' => array_values(array_filter(array_map(fn ($s) => ['status' => $s, 'count' => $n($s)], Workflow::openStatuses()), fn ($x) => $x['count'] > 0)),
            'revenueByMonth' => $revenueByMonth,
        ];
    }

    /* ------------------------------ Reports ------------------------------ */

    public static function validateReportRange(mixed $from, mixed $to): ?string
    {
        if (! Dates::isDay($from) || ! Dates::isDay($to)) {
            return 'Choose a date range.';
        }
        if ($from > $to) {
            return 'The start date must be before the end date.';
        }

        return null;
    }

    private static function reportDays(array $f): array
    {
        $days = [];
        for ($d = $f['from']; $d <= $f['to'] && count($days) < self::MAX_DAILY_POINTS; $d = Dates::shiftDay($d, 1)) {
            $days[] = $d;
        }

        return $days;
    }

    private static function overdueOrLate(array $c, int $now, array $sla): bool
    {
        $s = Sla::state($c, $now, $sla);

        return $s === 'overdue' || $s === 'late';
    }

    /** Distinct values in order of first appearance. */
    private static function distinct(array $values): array
    {
        return array_values(array_unique($values));
    }

    public static function caseReport(array $f, array $cases, int $now, array $sla, string $tz): array
    {
        $days = self::reportDays($f);
        $receivedDay = fn ($c) => Dates::dayIn($c['receivedAt'] ?? $c['createdAt'], $tz);
        $delivered = array_filter($cases, fn ($c) => ! empty($c['deliveredAt']) && ! empty($c['dueAt']));
        $months = self::distinct(array_map(fn ($d) => substr($d, 0, 7), $days));
        $done = fn ($c) => in_array($c['status'], Workflow::DONE_STATUSES, true);
        $byStatus = array_map(fn ($status) => ['status' => $status, 'count' => count(array_filter($cases, fn ($c) => $c['status'] === $status))], self::distinct(array_column($cases, 'status')));
        usort($byStatus, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'totals' => [
                'cases' => count($cases),
                'completed' => count(array_filter($cases, $done)),
                'open' => count(array_filter($cases, fn ($c) => Workflow::isOpen($c['status']))),
                'overdue' => count(array_filter($cases, fn ($c) => self::overdueOrLate($c, $now, $sla))),
                'onTimeRate' => Sla::onTimeRate($cases),
                'avgTurnaroundHours' => Num::average(array_values(array_map(fn ($c) => self::hoursBetween($c['receivedAt'], $c['deliveredAt']), $delivered))),
            ],
            'daily' => array_map(fn ($day) => [
                'label' => $day,
                'received' => count(array_filter($cases, fn ($c) => $receivedDay($c) === $day)),
                'completed' => count(array_filter($cases, fn ($c) => ! empty($c['deliveredAt']) && Dates::dayIn($c['deliveredAt'], $tz) === $day)),
                'overdue' => count(array_filter($cases, fn ($c) => ! empty($c['dueAt']) && Dates::dayIn($c['dueAt'], $tz) === $day && self::overdueOrLate($c, $now, $sla))),
            ], $days),
            'monthly' => array_map(function ($m) use ($cases, $receivedDay, $done, $now, $sla) {
                $in = array_filter($cases, fn ($c) => substr($receivedDay($c), 0, 7) === $m);

                return [
                    'label' => Dates::monthLabel($m),
                    'received' => count($in),
                    'completed' => count(array_filter($in, $done)),
                    'overdue' => count(array_filter($in, fn ($c) => self::overdueOrLate($c, $now, $sla))),
                ];
            }, $months),
            'byStatus' => $byStatus,
            'byCaseType' => array_map(fn ($t) => ['caseType' => $t, 'count' => count(array_filter($cases, fn ($c) => $c['caseType'] === $t))], self::distinct(array_column($cases, 'caseType'))),
        ];
    }

    public static function productionReport(array $cases): array
    {
        $stages = [
            ['Received → Assigned', 'receivedAt', 'assignedAt'],
            ['Assigned → Production start', 'assignedAt', 'productionStartedAt'],
            ['Production', 'productionStartedAt', 'productionCompletedAt'],
            ['Quality control', 'productionCompletedAt', 'qcCompletedAt'],
            ['Ready → Delivered', 'readyAt', 'deliveredAt'],
        ];

        return ['stages' => array_map(function ($s) use ($cases) {
            $xs = array_values(array_filter(array_map(fn ($c) => self::hoursBetween($c[$s[1]] ?? null, $c[$s[2]] ?? null), $cases), fn ($x) => $x !== null && $x >= 0));

            return ['stage' => $s[0], 'avgHours' => Num::average($xs), 'samples' => count($xs)];
        }, $stages)];
    }

    /** @param  list<array{id: string, name: string}>  $technicians  @param  array<string, int>  $qcFailuresByCase */
    public static function technicianReport(array $cases, array $technicians, array $qcFailuresByCase): array
    {
        $rows = [];
        foreach ($technicians as $t) {
            $list = array_values(array_filter($cases, fn ($c) => ($c['technicianId'] ?? null) === $t['id']));
            if (! $list) {
                continue;
            }
            $rows[] = [
                'technicianId' => $t['id'],
                'name' => $t['name'],
                'assigned' => count($list),
                'completed' => count(array_filter($list, fn ($c) => ! empty($c['productionCompletedAt']))),
                'onTimeRate' => Sla::onTimeRate($list),
                'avgProductionHours' => Num::average(array_values(array_filter(array_map(fn ($c) => self::hoursBetween($c['productionStartedAt'] ?? null, $c['productionCompletedAt'] ?? null), $list), fn ($x) => $x !== null))),
                'qcFailures' => array_sum(array_map(fn ($c) => $qcFailuresByCase[$c['id']] ?? 0, $list)),
            ];
        }

        return ['technicians' => $rows];
    }

    /** @param  list<array{id: string, name: string}>  $clinics */
    public static function clinicReport(array $cases, array $clinics): array
    {
        $rows = [];
        foreach ($clinics as $k) {
            $list = array_filter($cases, fn ($c) => $c['clinicId'] === $k['id']);
            if ($list) {
                $rows[] = ['clinicId' => $k['id'], 'name' => $k['name'], 'cases' => count($list), 'completed' => count(array_filter($list, fn ($c) => in_array($c['status'], Workflow::DONE_STATUSES, true)))];
            }
        }
        usort($rows, fn ($a, $b) => $b['cases'] <=> $a['cases']);

        return ['clinics' => $rows];
    }

    /** @param  array<string, array{total: float, paid: float, remaining: float}>  $invoiceByCase */
    public static function financialReport(array $f, array $cases, array $invoiceByCase, string $tz): array
    {
        $sum = fn (array $list, string $k) => Num::round1(array_sum(array_map(fn ($c) => $invoiceByCase[$c['id']][$k] ?? 0, $list)));
        $months = self::distinct(array_map(fn ($d) => substr($d, 0, 7), self::reportDays($f)));

        return [
            'totals' => ['revenue' => $sum($cases, 'total'), 'collected' => $sum($cases, 'paid'), 'outstanding' => $sum($cases, 'remaining')],
            'monthly' => array_map(fn ($m) => ['label' => Dates::monthLabel($m), 'revenue' => $sum(array_filter($cases, fn ($c) => substr(Dates::dayIn($c['receivedAt'] ?? $c['createdAt'], $tz), 0, 7) === $m), 'total')], $months),
            'byCaseType' => array_map(fn ($t) => ['caseType' => $t, 'revenue' => Num::round1(array_sum(array_map(fn ($c) => $c['caseType'] === $t ? $c['total'] : 0, $cases)))], self::distinct(array_column($cases, 'caseType'))),
            'clinics' => array_map(function ($clinicId) use ($cases, $sum) {
                $list = array_filter($cases, fn ($c) => $c['clinicId'] === $clinicId);

                return ['clinicId' => $clinicId, 'revenue' => $sum($list, 'total'), 'outstanding' => $sum($list, 'remaining')];
            }, self::distinct(array_column($cases, 'clinicId'))),
        ];
    }

    /* ------------------------ Directory statistics ----------------------- */

    /** Totals on patient, doctor and clinic detail pages. */
    public static function relationStats(array $cases, array $invoiceByCase, int $now, array $sla): array
    {
        $outstanding = 0.0;
        $billed = 0.0;
        foreach ($cases as $c) {
            if (isset($invoiceByCase[$c['id']])) {
                $billed += $invoiceByCase[$c['id']]['total'];
                $outstanding += $invoiceByCase[$c['id']]['remaining'];
            }
        }

        return [
            'totalCases' => count($cases),
            'activeCases' => count(array_filter($cases, fn ($c) => Workflow::isOpen($c['status']))),
            'completedCases' => count(array_filter($cases, fn ($c) => in_array($c['status'], Workflow::DONE_STATUSES, true))),
            'overdueCases' => count(array_filter($cases, fn ($c) => in_array($c['status'], Workflow::IN_LAB_STATUSES, true) && Sla::state($c, $now, $sla) === 'overdue')),
            'outstanding' => Num::round2($outstanding),
            'billed' => Num::round2($billed),
        ];
    }

    /** Workload figures in the technician list and profile. */
    public static function technicianWorkload(array $cases, int $now, array $sla, string $tz): array
    {
        $today = Dates::dayIn($now, $tz);
        $active = array_filter($cases, fn ($c) => in_array($c['status'], Workflow::PRODUCTION_STATUSES, true) || $c['status'] === 'quality_control');
        $done = array_values(array_filter($cases, fn ($c) => in_array($c['status'], Workflow::DONE_STATUSES, true)));

        return [
            'activeCases' => count($active),
            'completedCases' => count($done) + count(array_filter($cases, fn ($c) => $c['status'] === 'ready' || $c['status'] === 'out_for_delivery')),
            'dueToday' => count(array_filter($active, fn ($c) => ! empty($c['dueAt']) && Dates::dayIn($c['dueAt'], $tz) === $today && Sla::state($c, $now, $sla) !== 'overdue')),
            'overdue' => count(array_filter($active, fn ($c) => Sla::state($c, $now, $sla) === 'overdue')),
            'onTimeRate' => Sla::onTimeRate($done),
        ];
    }

    /** Statuses a technician still works on / counted as finished work on a profile. */
    public static function technicianActiveStatuses(): array
    {
        return array_values(array_filter(Workflow::IN_LAB_STATUSES, fn ($s) => $s !== 'ready' && $s !== 'out_for_delivery'));
    }

    public static function technicianFinishedStatuses(): array
    {
        return [...Workflow::DONE_STATUSES, 'ready', 'out_for_delivery'];
    }
}
