<?php

namespace App\Services;

use App\Domain\Dates;
use App\Domain\LabMessages;
use App\Domain\Workflow;
use App\Models\DentalCase;
use App\Support\LabSettings;
use Illuminate\Support\Facades\DB;

/**
 * Finds cases whose deadline is near or past and raises each alert once.
 *
 * A case is "claimed" for an alert with a conditional UPDATE (… WHERE
 * at_risk_notified_at IS NULL), so however many scans run at the same time —
 * or however often cron fires — every alert is sent exactly once per case.
 */
class DeadlineScanner
{
    /** @return array{checked: int, atRisk: int, overdue: int, notifications: int} */
    public function run(?int $now = null): array
    {
        $now ??= Dates::nowMs();
        $sla = LabSettings::sla();
        $at = Dates::fromMs($now);
        $dueBy = Dates::fromMs($now + $sla['atRiskHours'] * Dates::HOUR_MS);

        // Only cases inside the at-risk window that still owe an alert.
        $candidates = DentalCase::query()
            ->whereIn('status', Workflow::IN_LAB_STATUSES)
            ->whereNotNull('due_at')->where('due_at', '<=', $dueBy)
            ->where(fn ($q) => $q->whereNull('at_risk_notified_at')->orWhere(fn ($q) => $q->whereNull('overdue_notified_at')->where('due_at', '<=', $at)))
            ->get(['id', 'case_number', 'status', 'technician_id', 'received_at', 'due_at', 'delivered_at', 'at_risk_notified_at', 'overdue_notified_at']);

        $result = ['checked' => $candidates->count(), 'atRisk' => 0, 'overdue' => 0, 'notifications' => 0];
        foreach ($candidates as $c) {
            $alert = LabMessages::deadlineAlert(
                ['caseNumber' => $c->case_number, 'status' => $c->status, 'receivedAt' => Dates::iso($c->received_at), 'dueAt' => Dates::iso($c->due_at), 'deliveredAt' => Dates::iso($c->delivered_at)],
                ['atRisk' => $c->at_risk_notified_at !== null, 'overdue' => $c->overdue_notified_at !== null],
                $now,
                $sla,
            );
            if (! $alert) {
                continue;
            }

            DB::transaction(function () use ($c, $alert, $at, &$result) {
                $overdue = $alert['kind'] === 'overdue';
                $claim = DentalCase::query()->whereKey($c->id)->whereIn('status', Workflow::IN_LAB_STATUSES)
                    ->whereNull($overdue ? 'overdue_notified_at' : 'at_risk_notified_at')
                    ->update($overdue
                        ? ['overdue_notified_at' => $at, 'at_risk_notified_at' => $c->at_risk_notified_at ?? $at]
                        : ['at_risk_notified_at' => $at]);
                if (! $claim) {
                    return; // another scan got there first, or the case moved on
                }

                $users = [...Notifier::technician($c->technician_id), ...Notifier::recipients(LabMessages::RECIPIENTS['deadlineWatchers'])];
                if ($overdue) {
                    array_push($users, ...Notifier::recipients(LabMessages::RECIPIENTS['intake']));
                }
                $result['notifications'] += Notifier::send($users, $alert['content'], $c->id);
                $result[$overdue ? 'overdue' : 'atRisk']++;
            });
        }

        return $result;
    }
}
