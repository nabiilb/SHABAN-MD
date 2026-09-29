<?php

namespace App\Domain;

/**
 * Notification wording, recipient rules and the deadline-alert rule
 * (packages/shared/src/notifications.ts). Content is ['type', 'title', 'message'].
 */
final class LabMessages
{
    /** "Active users of these roles holding this permission" — how staff recipients are chosen. */
    public const RECIPIENTS = [
        'assigners' => ['permission' => Permissions::CASES_ASSIGN, 'roles' => ['lab_manager']],
        'deadlineWatchers' => ['permission' => Permissions::CASES_ASSIGN, 'roles' => ['lab_manager', 'admin']],
        'intake' => ['permission' => Permissions::CASES_ACCEPT, 'roles' => ['reception']],
        'intakeAndAdmin' => ['permission' => Permissions::CASES_ACCEPT, 'roles' => ['reception', 'admin']],
        'inspectors' => ['permission' => Permissions::QC_PERFORM, 'roles' => ['qc', 'lab_manager']],
        'dispatchers' => ['permission' => Permissions::DELIVERY_MANAGE, 'roles' => ['delivery', 'reception']],
        'finance' => ['permission' => Permissions::REPORTS_FINANCIAL, 'roles' => ['admin', 'super_admin']],
    ];

    private static function make(string $type, string $title, string $message): array
    {
        return ['type' => $type, 'title' => $title, 'message' => $message];
    }

    public static function caseReceived(string $n): array
    {
        return self::make('case_received', 'New case received', "{$n} is waiting for review and assignment.");
    }

    public static function caseSubmitted(string $n, string $clinic): array
    {
        return self::make('case_submitted', 'New case submitted', "{$n} was submitted by {$clinic}.");
    }

    public static function caseAccepted(string $n): array
    {
        return self::make('case_received', 'Case accepted', "{$n} was accepted and is awaiting technician assignment.");
    }

    public static function correctionRequested(string $n, string $note): array
    {
        return self::make('correction_requested', 'Correction requested', "{$n}: {$note}");
    }

    public static function caseResubmitted(string $n, string $clinic): array
    {
        return self::make('case_submitted', 'Case resubmitted', "{$n} was resubmitted by {$clinic} after correction.");
    }

    public static function caseRejected(string $n, string $note): array
    {
        return self::make('correction_requested', 'Case rejected', "{$n} was rejected: {$note}");
    }

    public static function caseAssigned(string $n, string $restorationType, ?int $hoursLeft): array
    {
        return self::make('case_assigned', 'New case assigned to you', "{$n} — {$restorationType}".($hoursLeft !== null ? ", {$hoursLeft}h remaining" : '').'.');
    }

    public static function qcRequired(string $n): array
    {
        return self::make('qc_required', 'Quality control required', "{$n} is waiting for inspection.");
    }

    public static function qcFailed(string $n, string $notes): array
    {
        return self::make('qc_failed', 'QC failed — rework required', "{$n} was returned: {$notes}");
    }

    public static function caseReady(string $n): array
    {
        return self::make('case_ready', 'Case ready for delivery', "{$n} passed quality control.");
    }

    public static function caseDispatched(string $n): array
    {
        return self::make('case_dispatched', 'Case on its way', "{$n} is out for delivery.");
    }

    public static function caseDelivered(string $n, string $deliveredTo): array
    {
        return self::make('case_delivered', 'Case delivered', "{$n} was delivered to {$deliveredTo}.");
    }

    public static function paymentReceived(float|int $amount, string $invoiceNumber): array
    {
        return self::make('payment_received', 'Payment received', Num::fixed2($amount)." received on {$invoiceNumber}.");
    }

    public static function caseOverdue(string $n, int|float $slaHours): array
    {
        return self::make('case_overdue', 'Case overdue', "{$n} has exceeded the {$slaHours}-hour deadline.");
    }

    public static function deadlineApproaching(string $n, int $hoursLeft): array
    {
        return self::make('deadline_approaching', 'Deadline approaching', "{$n} has about {$hoursLeft} hour".($hoursLeft === 1 ? '' : 's').' remaining.');
    }

    /**
     * Which deadline alert (if any) a case needs now, given the alerts already raised.
     * Each fires once per case; overdue implies at-risk.
     *
     * @return array{kind: string, content: array}|null
     */
    public static function deadlineAlert(array $c, array $flags, int $now, array $sla): ?array
    {
        if (! in_array($c['status'], Workflow::IN_LAB_STATUSES, true) || empty($c['dueAt'])) {
            return null;
        }
        $info = Sla::info($c, $now, $sla);
        if ($info['state'] === 'overdue' && empty($flags['overdue'])) {
            return ['kind' => 'overdue', 'content' => self::caseOverdue($c['caseNumber'], $sla['slaHours'])];
        }
        if (($info['state'] === 'at_risk' || $info['state'] === 'critical') && empty($flags['atRisk'])) {
            $hours = (int) max(1, Num::jsRound(($info['remainingMs'] ?? 0) / Dates::HOUR_MS));

            return ['kind' => 'at_risk', 'content' => self::deadlineApproaching($c['caseNumber'], $hours)];
        }

        return null;
    }
}
