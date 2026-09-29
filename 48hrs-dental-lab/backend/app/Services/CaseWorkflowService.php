<?php

namespace App\Services;

use App\Domain\ApiErrors;
use App\Domain\Dates;
use App\Domain\LabMessages;
use App\Domain\Num;
use App\Domain\Permissions;
use App\Domain\Sla;
use App\Domain\Workflow;
use App\Exceptions\ApiException;
use App\Models\CaseAssignment;
use App\Models\Delivery;
use App\Models\DentalCase;
use App\Models\QualityCheck;
use App\Models\QualityIssue;
use App\Models\Technician;
use App\Models\User;
use App\Support\Activity;
use App\Support\LabSettings;
use App\Support\Present;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Every case status change. The request is translated to a workflow action and
 * checked, in this order:
 *   404 — the case is outside the caller's scope
 *   409 — the step is not a valid transition from the current status
 *   403 — the caller may not perform it (permission, assigned technician, own clinic)
 *   422 — the step's input is incomplete or invalid
 * Then everything is written in one transaction. The status update is conditional
 * on the status we validated against, so two people acting on the same case at
 * once cannot both succeed: the second gets a 409.
 */
class CaseWorkflowService
{
    public static function invalidTransition(string $current): ApiException
    {
        return new ApiException(409, ApiErrors::INVALID_TRANSITION, ['status' => ['The case is currently "'.Workflow::STATUS_META[$current]['label'].'".']], ['currentStatus' => $current]);
    }

    public function perform(User $u, string $idOrNumber, string $endpoint, array $body): array
    {
        $now = Dates::nowMs();
        /** @var DentalCase $c */
        $c = CaseQuery::find($u, $idOrNumber)->with(['invoice', 'clinic:id,name', 'technician:id,name'])->first() ?? throw ApiException::notFound();

        $payload = Workflow::fromRequest($endpoint, $body, $c->status);
        if (! $payload) {
            throw self::invalidTransition($c->status);
        }
        $action = $payload['action'];
        $def = Workflow::ACTIONS[$action];
        if (! in_array($c->status, $def['from'], true)) {
            throw self::invalidTransition($c->status);
        }
        if (! Gate::forUser($u)->allows('perform', [$c, $action])) {
            throw ApiException::forbidden();
        }
        $maxPayment = $c->invoice ? Present::invoiceFigures($c->invoice, $now)['remaining'] : (float) $c->total;
        $inputErrors = Workflow::validateActionInput($payload, (float) $maxPayment);
        if ($inputErrors) {
            throw ApiException::validation(Workflow::toRequestErrors($endpoint, array_map(fn ($m) => [$m], $inputErrors)));
        }
        if ($action === 'accept' && ! empty($payload['payment']) && ! $u->hasPermission(Permissions::PAYMENTS_RECORD)) {
            throw ApiException::forbidden();
        }
        $assignee = $action === 'assign' ? Technician::find($payload['technicianId']) : null;
        if ($action === 'assign' && ! $assignee) {
            throw ApiException::validation(['technicianId' => ['Choose a technician.']]);
        }
        if ($assignee && ! $assignee->active) {
            throw ApiException::validation(['technicianId' => ['This technician is inactive.']]);
        }

        $settings = LabSettings::get();
        DB::transaction(function () use ($u, $c, $payload, $action, $def, $now, $settings, $assignee) {
            $at = Dates::fromMs($now);
            $data = ['status' => $def['to'], 'updated_at' => $at];
            switch ($action) {
                case 'accept':
                    $data['received_at'] = $at;
                    $data['due_at'] = Dates::fromMs(Sla::computeDueAt($now, $settings['slaHours']));
                    break;
                case 'resubmit':
                    $data['submitted_at'] = $at;
                    break;
                case 'assign':
                    $data['technician_id'] = $assignee->id;
                    $data['assigned_at'] = $at;
                    break;
                case 'start_production':
                    if (! $c->production_started_at) {
                        $data['production_started_at'] = $at;
                    }
                    break;
                case 'submit_qc':
                    $data['production_completed_at'] = $at;
                    break;
                case 'qc_pass':
                    $data['qc_completed_at'] = $at;
                    $data['ready_at'] = $at;
                    break;
                case 'qc_fail':
                    $data['rework_count'] = DB::raw('rework_count + 1');
                    break;
                case 'deliver':
                    $data['delivered_at'] = $at;
                    break;
                case 'confirm_receipt':
                    $data['completed_at'] = $at;
                    break;
                case 'cancel':
                    $data['cancelled_at'] = $at;
                    break;
            }
            // Conditional on the status we validated: a concurrent change makes this a 409, not a double transition.
            $updated = DentalCase::whereKey($c->id)->where('status', $c->status)->toBase()->update(array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s.v') : $v, $data));
            if (! $updated) {
                throw self::invalidTransition(DentalCase::whereKey($c->id)->value('status'));
            }
            $note = $this->sideEffects($u, $c, $payload, $now, $settings, $assignee);
            CaseService::recordHistory($u, $c->id, $c->status, $def['to'], $note);
            Activity::log($u, "case.{$action}", "{$def['label']}: {$c->case_number}", 'case', $c->id, $c->case_number);
        });

        return CaseService::present($u, $c->id);
    }

    /** Records, payments and notifications that go with each step; returns the history note. */
    private function sideEffects(User $u, DentalCase $c, array $p, int $now, array $settings, ?Technician $assignee): string
    {
        $at = Dates::fromMs($now);
        $note = trim((string) ($p['note'] ?? ''));
        $n = $c->case_number;
        $clinicName = $c->clinic->name;

        switch ($p['action']) {
            case 'accept':
                $invoice = $c->invoice ?? InvoiceIssuer::issue($c->fresh(), $settings, $now);
                if (! empty($p['payment'])) {
                    PaymentLedger::record($invoice->id, [...$p['payment'], 'notes' => 'Taken at acceptance'], $u->id, 'payment.', $now);
                }
                Notifier::send(Notifier::recipients(LabMessages::RECIPIENTS['assigners']), LabMessages::caseAccepted($n), $c->id, $u->id);

                return $note !== '' ? $note : "Accepted by {$u->name} — 48-hour clock started";
            case 'request_correction':
                Notifier::send(Notifier::clinic($c->clinic_id), LabMessages::correctionRequested($n, $note), $c->id);

                return $note;
            case 'resubmit':
                Notifier::send(Notifier::recipients(LabMessages::RECIPIENTS['intake']), LabMessages::caseResubmitted($n, $clinicName), $c->id);

                return $note;
            case 'reject':
                Notifier::send(Notifier::clinic($c->clinic_id), LabMessages::caseRejected($n, $note), $c->id);

                return $note;
            case 'assign':
                $reassigned = $c->technician_id && $c->technician_id !== $assignee->id;
                CaseAssignment::where('case_id', $c->id)->whereNull('unassigned_at')->update(['unassigned_at' => $at->format('Y-m-d H:i:s.v')]);
                CaseAssignment::create(['case_id' => $c->id, 'technician_id' => $assignee->id, 'assigned_by_id' => $u->id, 'note' => $note !== '' ? $note : null, 'assigned_at' => $at]);
                $hours = $c->due_at ? (int) max(0, Num::jsRound((Dates::ms($c->due_at) - $now) / Dates::HOUR_MS)) : null;
                Notifier::send(Notifier::technician($assignee->id), LabMessages::caseAssigned($n, $c->restoration_type, $hours), $c->id);

                return implode(' — ', array_filter([($reassigned ? 'Reassigned' : 'Assigned')." to {$assignee->name}", $note]));
            case 'start_production':
            case 'start_review':
            case 'cancel':
                return $note;
            case 'start_rework':
                return $note !== '' ? $note : 'Rework started';
            case 'submit_qc':
                Notifier::send(Notifier::recipients(LabMessages::RECIPIENTS['inspectors']), LabMessages::qcRequired($n), $c->id, $u->id);

                return $note !== '' ? $note : 'Production completed — submitted for quality control';
            case 'qc_pass':
            case 'qc_fail':
                $pass = $p['action'] === 'qc_pass';
                $notes = trim((string) ($p['qc']['notes'] ?? '')) ?: $note;
                $check = QualityCheck::create(['case_id' => $c->id, 'result' => $pass ? 'passed' : 'failed', 'rework_required' => ! $pass, 'notes' => $notes, 'checked_by_id' => $u->id, 'checked_at' => $at]);
                foreach (array_unique($p['qc']['issues'] ?? []) as $issue) {
                    QualityIssue::create(['quality_check_id' => $check->id, 'issue' => $issue]);
                }
                if ($pass) {
                    if (! Delivery::where('case_id', $c->id)->where('status', '!=', 'delivered')->exists()) {
                        Delivery::create(['case_id' => $c->id, 'status' => 'ready', 'method' => 'clinic_pickup', 'recorded_by_id' => $u->id, 'created_at' => $at]);
                    }
                    Notifier::send(Notifier::recipients(LabMessages::RECIPIENTS['dispatchers']), LabMessages::caseReady($n), $c->id, $u->id);

                    return $notes !== '' ? $notes : 'QC passed';
                }
                Notifier::send(Notifier::technician($c->technician_id), LabMessages::qcFailed($n, $notes), $c->id);

                return "QC failed — {$notes}";
            case 'dispatch':
            case 'deliver':
                $d = $p['delivery'];
                $open = Delivery::where('case_id', $c->id)->where('status', '!=', 'delivered')->orderByDesc('created_at')->first();
                $common = [
                    'method' => $d['method'],
                    'courier_name' => trim((string) ($d['courierName'] ?? '')) ?: ($open?->courier_name),
                    'notes' => trim((string) ($d['notes'] ?? '')) ?: ($open?->notes),
                    'recorded_by_id' => $u->id,
                ];
                if ($p['action'] === 'dispatch') {
                    $fields = [...$common, 'status' => 'out_for_delivery', 'dispatched_at' => $at];
                    $open ? $open->forceFill($fields)->save() : Delivery::create(['case_id' => $c->id, ...$fields, 'created_at' => $at]);
                    Notifier::send(Notifier::clinic($c->clinic_id), LabMessages::caseDispatched($n), $c->id);

                    return $note !== '' ? $note : 'Dispatched'.($common['courier_name'] ? " with {$common['courier_name']}" : '');
                }
                $deliveredTo = trim((string) ($d['deliveredTo'] ?? '')) ?: $clinicName;
                $receivedBy = trim((string) $d['receivedBy']);
                $fields = [...$common, 'status' => 'delivered', 'delivered_at' => $at, 'delivered_to' => $deliveredTo, 'received_by' => $receivedBy];
                $open ? $open->forceFill($fields)->save() : Delivery::create(['case_id' => $c->id, ...$fields, 'created_at' => $at]);
                $outcome = Sla::outcome(['status' => 'delivered', 'receivedAt' => Present::iso($c->received_at), 'dueAt' => Present::iso($c->due_at), 'deliveredAt' => Dates::iso($now)], $now, Sla::config($settings));
                Notifier::send([...Notifier::clinic($c->clinic_id), ...Notifier::recipients(LabMessages::RECIPIENTS['assigners'])], LabMessages::caseDelivered($n, $deliveredTo), $c->id, $u->id);

                return $note !== '' ? $note : "Delivered to {$deliveredTo}, received by {$receivedBy} — ".($outcome === 'completed_on_time' ? 'within' : 'outside')." the {$settings['slaHours']}-hour window";
            case 'confirm_receipt':
                return $note !== '' ? $note : 'Receipt confirmed by the clinic';
        }

        return $note;
    }
}
