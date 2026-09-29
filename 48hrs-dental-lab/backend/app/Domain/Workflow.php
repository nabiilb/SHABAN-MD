<?php

namespace App\Domain;

/**
 * The case lifecycle (packages/shared/src/workflow.ts + case-requests.ts).
 * An actor is ['user' => ['id', 'role', 'clinicId', 'technicianId'], 'permissions' => [...]].
 */
final class Workflow
{
    public const STATUS_META = [
        'submitted' => ['label' => 'Submitted', 'tone' => 'info', 'stage' => -1, 'nextActor' => 'Reception', 'open' => true],
        'correction' => ['label' => 'Correction requested', 'tone' => 'warning', 'stage' => -1, 'nextActor' => 'Clinic', 'open' => true],
        'received' => ['label' => 'Received', 'tone' => 'info', 'stage' => 0, 'nextActor' => 'Lab Manager', 'open' => true],
        'review' => ['label' => 'In review', 'tone' => 'info', 'stage' => 1, 'nextActor' => 'Lab Manager', 'open' => true],
        'assigned' => ['label' => 'Assigned', 'tone' => 'info', 'stage' => 2, 'nextActor' => 'Technician', 'open' => true],
        'in_production' => ['label' => 'In production', 'tone' => 'info', 'stage' => 3, 'nextActor' => 'Technician', 'open' => true],
        'rework' => ['label' => 'Rework required', 'tone' => 'danger', 'stage' => 3, 'nextActor' => 'Technician', 'open' => true],
        'quality_control' => ['label' => 'Quality control', 'tone' => 'warning', 'stage' => 4, 'nextActor' => 'Quality Control', 'open' => true],
        'ready' => ['label' => 'Ready', 'tone' => 'success', 'stage' => 5, 'nextActor' => 'Delivery', 'open' => true],
        'out_for_delivery' => ['label' => 'Out for delivery', 'tone' => 'success', 'stage' => 5, 'nextActor' => 'Delivery', 'open' => true],
        'delivered' => ['label' => 'Delivered', 'tone' => 'success', 'stage' => 6, 'nextActor' => 'Clinic (confirm receipt)', 'open' => false],
        'completed' => ['label' => 'Completed', 'tone' => 'neutral', 'stage' => 6, 'nextActor' => null, 'open' => false],
        'cancelled' => ['label' => 'Cancelled', 'tone' => 'neutral', 'stage' => -1, 'nextActor' => null, 'open' => false],
        'rejected' => ['label' => 'Rejected', 'tone' => 'danger', 'stage' => -1, 'nextActor' => null, 'open' => false],
    ];

    /** Statuses where the lab owns the case and the 48-hour clock is running. */
    public const IN_LAB_STATUSES = ['received', 'review', 'assigned', 'in_production', 'rework', 'quality_control', 'ready', 'out_for_delivery'];

    public const DONE_STATUSES = ['delivered', 'completed'];

    public const PRODUCTION_STATUSES = ['assigned', 'in_production', 'rework'];

    public const ACTIONS = [
        'accept' => ['key' => 'accept', 'label' => 'Accept case', 'from' => ['submitted'], 'to' => 'received', 'permission' => Permissions::CASES_ACCEPT, 'variant' => 'primary', 'needsInput' => true],
        'request_correction' => ['key' => 'request_correction', 'label' => 'Request correction', 'from' => ['submitted'], 'to' => 'correction', 'permission' => Permissions::CASES_ACCEPT, 'variant' => 'secondary', 'needsInput' => true, 'noteRequired' => true],
        'resubmit' => ['key' => 'resubmit', 'label' => 'Resubmit case', 'from' => ['correction'], 'to' => 'submitted', 'permission' => Permissions::CASES_SUBMIT, 'variant' => 'primary', 'needsInput' => true],
        'reject' => ['key' => 'reject', 'label' => 'Reject case', 'from' => ['submitted', 'correction'], 'to' => 'rejected', 'permission' => Permissions::CASES_ACCEPT, 'variant' => 'danger', 'needsInput' => true, 'noteRequired' => true],
        'start_review' => ['key' => 'start_review', 'label' => 'Start review', 'from' => ['received'], 'to' => 'review', 'permission' => Permissions::CASES_ASSIGN, 'variant' => 'secondary', 'needsInput' => false],
        'assign' => ['key' => 'assign', 'label' => 'Assign technician', 'from' => ['received', 'review', 'assigned'], 'to' => 'assigned', 'permission' => Permissions::CASES_ASSIGN, 'variant' => 'primary', 'needsInput' => true],
        'start_production' => ['key' => 'start_production', 'label' => 'Start production', 'from' => ['assigned'], 'to' => 'in_production', 'permission' => Permissions::CASES_UPDATE_STATUS, 'variant' => 'primary', 'needsInput' => false],
        'submit_qc' => ['key' => 'submit_qc', 'label' => 'Submit for QC', 'from' => ['in_production'], 'to' => 'quality_control', 'permission' => Permissions::CASES_UPDATE_STATUS, 'variant' => 'primary', 'needsInput' => true],
        'qc_pass' => ['key' => 'qc_pass', 'label' => 'Pass QC', 'from' => ['quality_control'], 'to' => 'ready', 'permission' => Permissions::QC_PERFORM, 'variant' => 'primary', 'needsInput' => true],
        'qc_fail' => ['key' => 'qc_fail', 'label' => 'Fail QC', 'from' => ['quality_control'], 'to' => 'rework', 'permission' => Permissions::QC_PERFORM, 'variant' => 'danger', 'needsInput' => true, 'noteRequired' => true],
        'start_rework' => ['key' => 'start_rework', 'label' => 'Start rework', 'from' => ['rework'], 'to' => 'in_production', 'permission' => Permissions::CASES_UPDATE_STATUS, 'variant' => 'primary', 'needsInput' => false],
        'dispatch' => ['key' => 'dispatch', 'label' => 'Dispatch', 'from' => ['ready'], 'to' => 'out_for_delivery', 'permission' => Permissions::DELIVERY_MANAGE, 'variant' => 'secondary', 'needsInput' => true],
        'deliver' => ['key' => 'deliver', 'label' => 'Mark delivered', 'from' => ['ready', 'out_for_delivery'], 'to' => 'delivered', 'permission' => Permissions::DELIVERY_MANAGE, 'variant' => 'primary', 'needsInput' => true],
        'confirm_receipt' => ['key' => 'confirm_receipt', 'label' => 'Confirm received', 'from' => ['delivered'], 'to' => 'completed', 'permission' => Permissions::CASES_CONFIRM_RECEIPT, 'variant' => 'primary', 'needsInput' => false],
        'cancel' => ['key' => 'cancel', 'label' => 'Cancel case', 'from' => ['submitted', 'correction', 'received', 'review', 'assigned', 'in_production', 'rework', 'quality_control', 'ready'], 'to' => 'cancelled', 'permission' => Permissions::CASES_CANCEL, 'variant' => 'danger', 'needsInput' => true, 'noteRequired' => true],
    ];

    /** REST sub-resource performing each action: POST /cases/{id}/{endpoint}. */
    public const ACTION_ENDPOINT = [
        'accept' => 'status', 'request_correction' => 'status', 'resubmit' => 'status', 'reject' => 'status', 'start_review' => 'status',
        'assign' => 'assign', 'start_production' => 'status', 'submit_qc' => 'status', 'qc_pass' => 'qc', 'qc_fail' => 'qc',
        'start_rework' => 'rework', 'dispatch' => 'delivery', 'deliver' => 'delivery', 'confirm_receipt' => 'status', 'cancel' => 'status',
    ];

    private const TECH_ACTIONS = ['start_production', 'submit_qc', 'start_rework'];

    private const CLIENT_ACTIONS = ['resubmit', 'confirm_receipt'];

    /** Action-payload paths → request body fields, per endpoint (for 422 keys). */
    private const FIELD_OF = [
        'qc' => ['qc.issues' => 'issues', 'note' => 'notes'],
        'delivery' => ['delivery.method' => 'method', 'delivery.courierName' => 'courierName', 'delivery.receivedBy' => 'receivedBy', 'note' => 'notes'],
    ];

    public static function statuses(): array
    {
        return array_keys(self::STATUS_META);
    }

    public static function openStatuses(): array
    {
        return array_keys(array_filter(self::STATUS_META, fn ($m) => $m['open']));
    }

    public static function isOpen(string $status): bool
    {
        return self::STATUS_META[$status]['open'];
    }

    public static function resolveTransition(string $from, string $to): ?string
    {
        foreach (self::ACTIONS as $key => $a) {
            if ($a['to'] === $to && in_array($from, $a['from'], true)) {
                return $key;
            }
        }

        return null;
    }

    /** Row-level scope: may the actor see this case at all? */
    public static function canViewCase(array $actor, array $c): bool
    {
        $p = $actor['permissions'];
        if (! Permissions::has($p, Permissions::CASES_VIEW)) {
            return false;
        }
        if (Permissions::has($p, Permissions::CASES_VIEW_ALL)) {
            return true;
        }
        if (! empty($actor['user']['clinicId'])) {
            return ($c['clinicId'] ?? null) === $actor['user']['clinicId'];
        }
        if (! empty($actor['user']['technicianId'])) {
            return ($c['technicianId'] ?? null) === $actor['user']['technicianId'];
        }

        return false;
    }

    /** Single source of truth for "may this actor run this action on this case". */
    public static function canPerformAction(string $action, array $c, array $actor): bool
    {
        $def = self::ACTIONS[$action];
        $p = $actor['permissions'];
        if (! in_array($c['status'], $def['from'], true) || ! Permissions::has($p, $def['permission']) || ! self::canViewCase($actor, $c)) {
            return false;
        }
        // Production steps: only the assigned technician, unless the actor can assign (lab manager / admin).
        if (in_array($action, self::TECH_ACTIONS, true) && ! Permissions::has($p, Permissions::CASES_ASSIGN)) {
            $tech = $actor['user']['technicianId'] ?? null;
            if (! $tech || $tech !== ($c['technicianId'] ?? null)) {
                return false;
            }
        }
        // Client-portal steps: only the owning clinic.
        $clinic = $actor['user']['clinicId'] ?? null;
        if (in_array($action, self::CLIENT_ACTIONS, true) && $clinic && $clinic !== ($c['clinicId'] ?? null)) {
            return false;
        }

        return true;
    }

    /**
     * Input rules for each action (the 422 of a workflow step). Keys are payload paths.
     *
     * @return array<string, string>
     */
    public static function validateActionInput(array $p, ?float $maxPayment = null): array
    {
        $errors = [];
        $action = $p['action'];
        $def = self::ACTIONS[$action];
        $note = trim((string) ($p['note'] ?? ''));
        if ($note === '' && $action === 'qc_fail') {
            $note = trim((string) ($p['qc']['notes'] ?? ''));
        }
        if (! empty($def['noteRequired']) && $note === '') {
            $errors['note'] = $action === 'qc_fail' ? 'Describe what must be reworked.' : 'Please give a reason.';
        }
        if ($action === 'assign' && empty($p['technicianId'])) {
            $errors['technicianId'] = 'Choose a technician.';
        }
        if ($action === 'qc_fail' && empty($p['qc']['issues'])) {
            $errors['qc.issues'] = 'Select at least one issue.';
        }
        if ($action === 'accept' && ! empty($p['payment'])) {
            $amountError = Billing::validatePaymentAmount($p['payment']['amount'] ?? null, $maxPayment ?? INF);
            if ($amountError) {
                $errors['payment.amount'] = $amountError;
            }
            if (Billing::referenceRequired($p['payment']['method'] ?? null) && trim((string) ($p['payment']['reference'] ?? '')) === '') {
                $errors['payment.reference'] = 'Enter the transaction reference.';
            }
        }
        if ($action === 'dispatch' || $action === 'deliver') {
            $d = $p['delivery'] ?? [];
            if (empty($d['method'])) {
                $errors['delivery.method'] = 'Choose a delivery method.';
            }
            if ($action === 'dispatch' && ($d['method'] ?? null) !== 'clinic_pickup' && trim((string) ($d['courierName'] ?? '')) === '') {
                $errors['delivery.courierName'] = 'Enter the courier name.';
            }
            if ($action === 'deliver' && trim((string) ($d['receivedBy'] ?? '')) === '') {
                $errors['delivery.receivedBy'] = 'Enter who received the case.';
            }
        }

        return $errors;
    }

    /**
     * The action a validated request body asks for on a case in `$current` status,
     * or null when it is not a valid transition from there (→ 409).
     */
    public static function fromRequest(string $endpoint, array $b, string $current): ?array
    {
        switch ($endpoint) {
            case 'status':
                $action = self::resolveTransition($current, $b['status']);
                if (! $action || self::ACTION_ENDPOINT[$action] !== 'status') {
                    return null;
                }

                return ['action' => $action, 'note' => $b['note'] ?? null, 'payment' => $action === 'accept' ? ($b['payment'] ?? null) : null];
            case 'assign':
                return ['action' => 'assign', 'technicianId' => $b['technicianId'] ?? '', 'note' => $b['note'] ?? null];
            case 'qc':
                $fail = $b['result'] === 'fail';

                return ['action' => $fail ? 'qc_fail' : 'qc_pass', 'note' => $b['notes'], 'qc' => ['issues' => $b['issues'], 'notes' => $b['notes'], 'reworkRequired' => $fail]];
            case 'rework':
                return ['action' => 'start_rework', 'note' => $b['note'] ?? null];
            case 'delivery':
                return [
                    'action' => $b['status'] === 'out_for_delivery' ? 'dispatch' : 'deliver',
                    'note' => $b['notes'] ?? null,
                    'delivery' => ['method' => $b['method'], 'courierName' => $b['courierName'] ?? null, 'deliveredTo' => $b['deliveredTo'] ?? null, 'receivedBy' => $b['receivedBy'] ?? null, 'notes' => $b['notes'] ?? null],
                ];
        }

        return null;
    }

    /** 422 keys named after the request body the client sent. */
    public static function toRequestErrors(string $endpoint, array $errors): array
    {
        $map = self::FIELD_OF[$endpoint] ?? [];
        $out = [];
        foreach ($errors as $k => $v) {
            $out[$map[$k] ?? $k] = $v;
        }

        return $out;
    }
}
