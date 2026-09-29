<?php

namespace Tests\Unit;

use App\Domain\Analytics;
use App\Domain\ApiErrors;
use App\Domain\Billing;
use App\Domain\Catalog;
use App\Domain\Dates;
use App\Domain\LabMessages;
use App\Domain\Num;
use App\Domain\Permissions;
use App\Domain\Sla;
use App\Domain\Workflow;
use Tests\TestCase;

/**
 * The PHP business rules against the TypeScript rules the React app runs
 * (tests/Fixtures/shared-rules.json, exported by `npm run contract:export`).
 */
class SharedRulesParityTest extends TestCase
{
    private static array $fx;

    public static function setUpBeforeClass(): void
    {
        self::$fx = json_decode(file_get_contents(__DIR__.'/../Fixtures/shared-rules.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Numbers compared as numbers (JSON 1 == PHP 1.0), floats to 1e-9. */
    private function assertSameData(mixed $expected, mixed $actual, string $path = '$'): void
    {
        if (is_array($expected) && is_array($actual)) {
            $this->assertSame(array_keys($expected), array_keys($actual), "keys at {$path}");
            foreach ($expected as $k => $v) {
                $this->assertSameData($v, $actual[$k], "{$path}.{$k}");
            }

            return;
        }
        if ((is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) {
            $this->assertEqualsWithDelta($expected, $actual, 1e-9, "number at {$path}");

            return;
        }
        $this->assertSame($expected, $actual, "value at {$path}");
    }

    public function test_catalogues_match(): void
    {
        $c = self::$fx['catalogue'];
        $this->assertSame($c['permissions'], Permissions::catalogue());
        $this->assertSame($c['allPermissionKeys'], Permissions::allKeys());
        $this->assertSame($c['roles'], Permissions::defaultRoles());
        $this->assertSame($c['roleLabels'], Permissions::ROLE_LABELS);
        $this->assertSame($c['roleOrder'], Permissions::ROLE_ORDER);
        $this->assertSame($c['statusMeta'], Workflow::STATUS_META);
        $this->assertSame($c['statuses'], Workflow::statuses());
        $this->assertSame($c['openStatuses'], Workflow::openStatuses());
        $this->assertSame($c['inLabStatuses'], Workflow::IN_LAB_STATUSES);
        $this->assertSame($c['doneStatuses'], Workflow::DONE_STATUSES);
        $this->assertSame($c['productionStatuses'], Workflow::PRODUCTION_STATUSES);
        $this->assertSame($c['caseActions'], Workflow::ACTIONS);
        $this->assertSame($c['actionEndpoint'], Workflow::ACTION_ENDPOINT);
        $this->assertSame($c['caseTypeLabels'], Catalog::CASE_TYPE_LABELS);
        $this->assertSame($c['priorityMeta'], Catalog::PRIORITY_META);
        $this->assertSame($c['paymentMethodLabels'], Catalog::PAYMENT_METHOD_LABELS);
        $this->assertSame($c['deliveryMethodLabels'], Catalog::DELIVERY_METHOD_LABELS);
        $this->assertSame($c['attachmentCategoryLabels'], Catalog::ATTACHMENT_CATEGORY_LABELS);
        $this->assertSame($c['qcIssueLabels'], Catalog::QC_ISSUE_LABELS);
        $this->assertSame($c['allowedFileExtensions'], Catalog::ALLOWED_FILE_EXTENSIONS);
        $this->assertSame($c['maxFileMb'], Catalog::MAX_FILE_MB);
        $this->assertSame($c['defaultLabSettings'], Catalog::DEFAULT_LAB_SETTINGS);
        $this->assertSame($c['defaultSla'], Sla::defaultConfig());
        $this->assertSame($c['dentureTypes'], Catalog::DENTURE_TYPES);
        $this->assertSame($c['apiErrors'], ApiErrors::all());
        $this->assertSame($c['recipients'], LabMessages::RECIPIENTS);
    }

    public function test_permission_matrix_for_every_action_status_and_actor(): void
    {
        $w = self::$fx['workflow'];
        $checked = 0;
        foreach ($w['actors'] as $actorKey => $actor) {
            foreach ($w['caseRefs'] as $refKey => $ref) {
                $this->assertSame($w['viewMatrix']["{$actorKey}|{$refKey}"], Workflow::canViewCase($actor, $ref), "view {$actorKey}|{$refKey}");
                $rows = explode(',', $w['matrix']["{$actorKey}|{$refKey}"]);
                foreach ($w['actions'] as $ai => $action) {
                    foreach (Workflow::statuses() as $si => $status) {
                        $expected = $rows[$ai][$si] === '1';
                        $this->assertSame($expected, Workflow::canPerformAction($action, [...$ref, 'status' => $status], $actor), "{$action} on {$status} by {$actorKey} ({$refKey})");
                        $checked++;
                    }
                }
            }
        }
        $this->assertGreaterThan(5000, $checked);
    }

    public function test_transitions_requests_and_input_rules(): void
    {
        $w = self::$fx['workflow'];
        foreach ($w['transitions'] as $t) {
            $this->assertSame($t['action'], Workflow::resolveTransition($t['from'], $t['to']), "{$t['from']} → {$t['to']}");
        }
        foreach ($w['requests'] as $r) {
            $this->assertSameData($r['payload'], $this->withoutNulls(Workflow::fromRequest($r['endpoint'], $r['body'], $r['current']), $r['payload']), "{$r['endpoint']} ".json_encode($r['body'])." from {$r['current']}");
        }
        foreach ($w['inputs'] as $i) {
            $this->assertSame($i['expected'], Workflow::validateActionInput($i['payload'], $i['maxPayment'] === null ? null : (float) $i['maxPayment']), json_encode($i['payload']));
        }
        foreach ($w['requestErrors'] as $e) {
            $this->assertSame($e['expected'], Workflow::toRequestErrors($e['endpoint'], $e['input']));
        }
    }

    /** JSON drops undefined keys; PHP keeps them as null. Compare on the expected shape. */
    private function withoutNulls(?array $actual, ?array $expected): ?array
    {
        if ($actual === null || $expected === null) {
            return $actual;
        }
        $out = [];
        foreach ($actual as $k => $v) {
            if (! array_key_exists($k, $expected) && $v === null) {
                continue;
            }
            $out[$k] = is_array($v) && is_array($expected[$k] ?? null) ? $this->withoutNulls($v, $expected[$k]) : $v;
        }

        return $out;
    }

    public function test_sla_states(): void
    {
        foreach (self::$fx['sla'] as $s) {
            $info = Sla::info($s['input'], $s['now'], Sla::defaultConfig());
            $info['dueAt'] = Dates::iso($info['dueAt']);
            $this->assertSameData($s['expected'], $info, json_encode($s['input']));
        }
    }

    public function test_billing(): void
    {
        $b = self::$fx['billing'];
        foreach ($b['units'] as $u) {
            $this->assertSame($u['expected'], Billing::unitsFor($u['mode'], $u['teeth'], $u['dentureType']));
        }
        foreach ($b['prices'] as $p) {
            $this->assertSameData($p['expected'], Billing::priceCase($p['service']['unitMode'], $p['service']['unitPrice'], $p['teeth'], $p['dentureType'], $p['urgent'], $p['fee']));
        }
        foreach ($b['invoiceStatus'] as $i) {
            $this->assertSame($i['expected'], Billing::invoiceStatus($i['total'], $i['paid'], $i['dueDate'], $i['now']), json_encode($i));
        }
        foreach ($b['paymentAmount'] as $p) {
            $amount = $p['amount'] === 'NaN' ? NAN : $p['amount'];
            $this->assertSame($p['expected'], Billing::validatePaymentAmount($amount, $p['remaining']), json_encode($p));
        }
        foreach ($b['referenceRequired'] as $r) {
            $this->assertSame($r['expected'], Billing::referenceRequired($r['method']));
        }
        foreach ($b['round2'] as $r) {
            $this->assertEqualsWithDelta($r['expected'], Num::round2($r['n']), 1e-12, "round2({$r['n']})");
        }
    }

    public function test_notification_wording_and_deadline_alerts(): void
    {
        $t = self::$fx['notifications']['templates'];
        $n = 'DL-2026-00001';
        $this->assertSame($t['caseReceived'], LabMessages::caseReceived($n));
        $this->assertSame($t['caseSubmitted'], LabMessages::caseSubmitted($n, 'Smile Dental'));
        $this->assertSame($t['caseAccepted'], LabMessages::caseAccepted($n));
        $this->assertSame($t['correctionRequested'], LabMessages::correctionRequested($n, 'Fix shade'));
        $this->assertSame($t['caseResubmitted'], LabMessages::caseResubmitted($n, 'Smile Dental'));
        $this->assertSame($t['caseRejected'], LabMessages::caseRejected($n, 'Dup'));
        $this->assertSame($t['caseAssigned'], LabMessages::caseAssigned($n, 'Zirconia Crown', 31));
        $this->assertSame($t['caseAssignedNoDeadline'], LabMessages::caseAssigned($n, 'Zirconia Crown', null));
        $this->assertSame($t['qcRequired'], LabMessages::qcRequired($n));
        $this->assertSame($t['qcFailed'], LabMessages::qcFailed($n, 'Too light'));
        $this->assertSame($t['caseReady'], LabMessages::caseReady($n));
        $this->assertSame($t['caseDispatched'], LabMessages::caseDispatched($n));
        $this->assertSame($t['caseDelivered'], LabMessages::caseDelivered($n, 'Front desk'));
        $this->assertSame($t['paymentReceived'], LabMessages::paymentReceived(12.5, 'INV-2026-00001'));
        $this->assertSame($t['caseOverdue'], LabMessages::caseOverdue($n, 48));
        $this->assertSame($t['deadlineApproaching1'], LabMessages::deadlineApproaching($n, 1));
        $this->assertSame($t['deadlineApproaching5'], LabMessages::deadlineApproaching($n, 5));
        foreach (self::$fx['notifications']['deadline'] as $d) {
            $this->assertSame($d['expected'], LabMessages::deadlineAlert($d['case'], $d['flags'], self::$fx['referenceNow'], Sla::defaultConfig()), json_encode($d));
        }
    }

    public function test_keys_files_and_dates(): void
    {
        $k = self::$fx['keys'];
        $this->assertSame($k['caseNumber'], \App\Support\Numbers::caseNumber(2026, 7));
        $this->assertSame($k['invoiceNumber'], \App\Support\Numbers::invoiceNumber(2026, 123456));
        $this->assertSame($k['patientCode'], \App\Support\Numbers::patientCode(1024));
        foreach (self::$fx['files'] as $f) {
            $this->assertSame($f['extension'], Catalog::extensionOf($f['name']), $f['name']);
            $this->assertSame($f['category'], Catalog::categoryForExtension($f['extension']), $f['name']);
            $this->assertSame($f['error'], Catalog::validateFile($f['name'], 10), $f['name']);
            $this->assertSame($f['tooBig'], Catalog::validateFile($f['name'], 60 * 1_048_576), $f['name']);
        }
        $d = self::$fx['dates'];
        foreach ($d['dayIn'] as $x) {
            $this->assertSame($x['day'], Dates::dayIn($x['t'], $x['tz']), json_encode($x));
        }
        foreach ($d['startOfDay'] as $x) {
            $this->assertSame($x['start'], Dates::iso(Dates::startOfDay($x['day'], $x['tz'])), json_encode($x));
            $this->assertSame($x['end'], Dates::iso(Dates::endOfDayExclusive($x['day'], $x['tz'])), json_encode($x));
        }
        foreach ($d['shiftDay'] as $x) {
            $this->assertSame($x['out'], Dates::shiftDay($x['day'], $x['n']));
        }
        foreach ($d['shiftMonth'] as $x) {
            $this->assertSame($x['out'], Dates::shiftMonth($x['day'], $x['n']));
        }
        foreach ($d['monthLabels'] as $x) {
            $this->assertSame($x['label'], Dates::monthLabel($x['m']));
        }
        foreach ($d['isDay'] as $x) {
            $this->assertSame($x['out'], Dates::isDay($x['v']), json_encode($x));
        }
    }

    public function test_dashboard_and_reports_aggregate_identically(): void
    {
        $a = self::$fx['analytics'];
        $tz = $a['timezone'];
        $byId = array_column($a['cases'], null, 'id');
        foreach ($a['dashboards'] as $d) {
            $this->assertSameData($d['expected'], Analytics::buildDashboard($d['input'], $a['sla'], $tz), "dashboard {$d['input']['period']}");
        }
        foreach ($a['reports'] as $r) {
            $cases = array_map(fn ($id) => $byId[$id], $r['caseIds']);
            $inv = array_intersect_key($a['invoices'], array_flip($r['caseIds']));
            $this->assertSameData($r['expected']['cases'], Analytics::caseReport($r['filters'], $cases, $a['now'], $a['sla'], $tz), 'cases '.json_encode($r['filters']));
            $this->assertSameData($r['expected']['production'], Analytics::productionReport($cases), 'production');
            $this->assertSameData($r['expected']['technicians'], Analytics::technicianReport($cases, $r['technicians'], $r['qcFailures']), 'technicians');
            $this->assertSameData($r['expected']['clinics'], Analytics::clinicReport($cases, $r['clinics']), 'clinics');
            $this->assertSameData($r['expected']['financial'], Analytics::financialReport($r['filters'], $cases, $inv, $tz), 'financial');
        }
        foreach ($a['relation'] as $r) {
            $cases = array_map(fn ($id) => $byId[$id], $r['caseIds']);
            $this->assertSameData($r['expected'], Analytics::relationStats($cases, $a['invoices'], $a['now'], $a['sla']), "relation {$r['clinicId']}");
        }
        foreach ($a['workloads'] as $w) {
            $cases = array_map(fn ($id) => $byId[$id], $w['caseIds']);
            $this->assertSameData($w['expected'], Analytics::technicianWorkload($cases, $a['now'], $a['sla'], $tz), "workload {$w['technicianId']}");
        }
        $this->assertEqualsWithDelta($a['onTimeRate'], Sla::onTimeRate($a['cases']), 1e-12);
        foreach ($a['reportRanges'] as $r) {
            $this->assertSame($r['expected'], Analytics::validateReportRange($r['from'], $r['to']), json_encode($r));
        }
    }
}
