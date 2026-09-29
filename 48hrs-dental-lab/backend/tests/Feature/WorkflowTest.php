<?php

namespace Tests\Feature;

use App\Domain\Workflow;
use App\Models\ActivityLog;
use App\Models\CaseAssignment;
use App\Models\CaseStatusHistory;
use App\Models\DentalCase;
use App\Models\QualityIssue;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApiClient;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    private const HOUR = 3_600_000;

    private function step(ApiClient $c, string $id, string $endpoint, array $body, int $expected = 200): array
    {
        $res = $c->post("/cases/{$id}/{$endpoint}", $body);
        $this->assertSame($expected, $res->status(), "{$endpoint} ".json_encode($body).' → '.$res->getContent());

        return $res->json() ?? [];
    }

    public function test_received_at_is_server_time_and_due_at_is_48_hours_later_ignoring_the_client_clock(): void
    {
        $c = $this->createReceivedCase($this->as('reception'), ['receivedAt' => '2001-01-01T00:00:00Z']);
        $received = strtotime($c['receivedAt']) * 1000;
        $this->assertSame(self::NOW, $received);
        $this->assertSame(48 * self::HOUR, strtotime($c['dueAt']) * 1000 - $received);
        $this->assertSame('received', $c['status']);
        $this->assertNotNull($c['invoice']);
    }

    public function test_a_portal_submission_has_no_clock_until_reception_accepts_it(): void
    {
        $res = $this->as('client')->post('/cases', ['patientId' => 'pat_1024', 'doctorId' => 'doc_amina', 'clinicId' => 'cln_banadir', 'serviceId' => 'svc_emax', 'shade' => 'A1', 'teeth' => [8], 'priority' => 'normal', 'instructions' => '']);
        $res->assertCreated()->assertJson(['status' => 'submitted', 'clinicId' => 'cln_smile', 'receivedAt' => null, 'dueAt' => null, 'invoice' => null]);
        $this->advanceMinutes(90);
        $accepted = $this->step($this->as('reception'), $res->json('id'), 'status', ['status' => 'received']);
        $this->assertSame(self::NOW + 90 * 60_000, strtotime($accepted['receivedAt']) * 1000);
        $this->assertSame(48 * self::HOUR, (strtotime($accepted['dueAt']) - strtotime($accepted['receivedAt'])) * 1000);
        $this->assertNotNull($accepted['invoice']);
    }

    public function test_complete_production_flow_with_qc_failure_and_rework(): void
    {
        $reception = $this->as('reception');
        $manager = $this->as('manager');
        $tech = $this->as('technician');
        $qc = $this->as('qc');
        $delivery = $this->as('delivery');
        $client = $this->as('client');

        $c = $this->createReceivedCase($reception);
        $this->step($manager, $c['id'], 'status', ['status' => 'review']);
        $assigned = $this->step($manager, $c['id'], 'assign', ['technicianId' => 'tec_fatima', 'note' => 'Priority client']);
        $this->assertSame('tec_fatima', $assigned['technician']['id']);
        $this->step($tech, $c['id'], 'status', ['status' => 'in_production']);
        $this->step($tech, $c['id'], 'status', ['status' => 'quality_control', 'note' => 'Glazed']);

        $failed = $this->step($qc, $c['id'], 'qc', ['result' => 'fail', 'issues' => ['shade', 'contacts'], 'notes' => 'Shade too light']);
        $this->assertSame(['rework', 1], [$failed['status'], $failed['reworkCount']]);
        $check = end($failed['qualityChecks']);
        $this->assertSame(['result' => 'failed', 'reworkRequired' => true, 'issues' => ['contacts', 'shade'], 'notes' => 'Shade too light', 'checkedByName' => 'Idil Hassan'], array_intersect_key($check, array_flip(['result', 'reworkRequired', 'issues', 'notes', 'checkedByName'])));
        $this->step($tech, $c['id'], 'rework', []);
        $this->step($tech, $c['id'], 'status', ['status' => 'quality_control']);
        $passed = $this->step($qc, $c['id'], 'qc', ['result' => 'pass', 'issues' => [], 'notes' => 'Fit verified']);
        $this->assertSame('ready', $passed['status']);
        $this->assertSame('ready', end($passed['deliveries'])['status']);

        $this->assertNull($passed['invoice']); // QC has no invoices.view: money is not in its response
        $invoice = $reception->get("/cases/{$c['id']}")->json('invoice');
        $pay = $reception->post('/payments', ['invoiceId' => $invoice['id'], 'amount' => $passed['total'], 'method' => 'cash']);
        $pay->assertCreated()->assertJson(['status' => 'paid', 'remaining' => 0, 'paid' => $passed['total']]);

        $out = $this->step($delivery, $c['id'], 'delivery', ['status' => 'out_for_delivery', 'method' => 'lab_courier', 'courierName' => 'Bashir Omar']);
        $this->assertSame(['out_for_delivery', 'Bashir Omar'], [end($out['deliveries'])['status'], end($out['deliveries'])['courierName']]);
        $delivered = $this->step($delivery, $c['id'], 'delivery', ['status' => 'delivered', 'method' => 'lab_courier', 'receivedBy' => 'Nurse Hawa', 'deliveredTo' => 'Smile front desk', 'notes' => 'Left with nurse']);
        $last = end($delivered['deliveries']);
        $this->assertSame(['delivered', 'Nurse Hawa', 'Smile front desk', 'Left with nurse', 'Bashir Omar'], [$last['status'], $last['receivedBy'], $last['deliveredTo'], $last['notes'], $last['recordedByName']]);
        $this->assertNotNull($last['deliveredAt']);
        $this->assertNotNull($delivered['deliveredAt']);

        $this->assertSame('completed', $this->step($client, $c['id'], 'status', ['status' => 'completed'])['status']);

        // Persisted history, in order, with actors and notes.
        $history = CaseStatusHistory::where('case_id', $c['id'])->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['received', 'review', 'assigned', 'in_production', 'quality_control', 'rework', 'in_production', 'quality_control', 'ready', 'out_for_delivery', 'delivered', 'completed'], $history->pluck('to_status')->all());
        $this->assertSame('Assigned to Fatima Nur — Priority client', $history[2]->note);
        $this->assertSame('QC failed — Shade too light', $history[5]->note);
        $this->assertSame('Left with nurse', $history[10]->note);
        $this->assertSame(1, CaseAssignment::where('case_id', $c['id'])->count());
        $this->assertSame(2, QualityIssue::whereHas('qualityCheck', fn ($q) => $q->where('case_id', $c['id']))->count());
        $this->assertGreaterThanOrEqual(12, ActivityLog::where('subject_id', $c['id'])->count());
    }

    public function test_reassignment_closes_the_previous_assignment(): void
    {
        $manager = $this->as('manager');
        $c = $this->createReceivedCase($this->as('reception'));
        $this->step($manager, $c['id'], 'assign', ['technicianId' => 'tec_fatima']);
        $this->advanceMinutes(1);
        $again = $this->step($manager, $c['id'], 'assign', ['technicianId' => 'tec_ahmed']);
        $this->assertSame('tec_ahmed', $again['technician']['id']);
        $log = CaseAssignment::where('case_id', $c['id'])->orderBy('assigned_at')->get()->map(fn ($a) => [$a->technician_id, $a->unassigned_at !== null])->all();
        $this->assertSame([['tec_fatima', true], ['tec_ahmed', false]], $log);
        $this->assertSame('Reassigned to Ahmed Hassan', end($again['history'])['note']);
    }

    public function test_invalid_workflow_transitions_are_409_with_the_standard_message(): void
    {
        $reception = $this->as('reception');
        $c = $this->createReceivedCase($reception);
        $this->as('qc')->post("/cases/{$c['id']}/qc", ['result' => 'pass', 'issues' => [], 'notes' => ''])
            ->assertStatus(409)->assertJson(['message' => 'Invalid workflow transition.', 'currentStatus' => 'received']);
        $reception->post("/cases/{$c['id']}/status", ['status' => 'delivered'])->assertStatus(409);
        $reception->post("/cases/{$c['id']}/status", ['status' => 'assigned'])->assertStatus(409); // assign has its own endpoint
        $this->assertSame(1, CaseStatusHistory::where('case_id', $c['id'])->count());
    }

    public function test_step_input_is_validated_with_the_request_field_names(): void
    {
        $reception = $this->as('reception');
        $manager = $this->as('manager');
        $qc = $this->as('qc');
        $tech = $this->as('technician');
        $delivery = $this->as('delivery');
        $c = $this->createReceivedCase($reception);
        $this->assertArrayHasKey('technicianId', $manager->post("/cases/{$c['id']}/assign", [])->json('errors'));
        $manager->post("/cases/{$c['id']}/assign", ['technicianId' => 'tec_nobody'])->assertStatus(422);
        $this->step($manager, $c['id'], 'assign', ['technicianId' => 'tec_fatima']);
        $this->step($tech, $c['id'], 'status', ['status' => 'in_production']);
        $this->step($tech, $c['id'], 'status', ['status' => 'quality_control']);
        $qcFail = $qc->post("/cases/{$c['id']}/qc", ['result' => 'fail', 'issues' => [], 'notes' => '']);
        $qcFail->assertStatus(422);
        $keys = array_keys($qcFail->json('errors'));
        sort($keys);
        $this->assertSame(['issues', 'notes'], $keys);
        $this->assertArrayHasKey('result', $qc->post("/cases/{$c['id']}/qc", ['result' => 'maybe'])->json('errors'));
        $this->step($qc, $c['id'], 'qc', ['result' => 'pass', 'issues' => [], 'notes' => '']);
        $noCourier = $delivery->post("/cases/{$c['id']}/delivery", ['status' => 'out_for_delivery', 'method' => 'lab_courier']);
        $noCourier->assertStatus(422);
        $this->assertArrayHasKey('courierName', $noCourier->json('errors'));
        $this->assertArrayHasKey('receivedBy', $delivery->post("/cases/{$c['id']}/delivery", ['status' => 'delivered', 'method' => 'clinic_pickup'])->json('errors'));
        $reception->post("/cases/{$c['id']}/status", ['status' => 'cancelled'])->assertStatus(403); // reception cannot cancel
    }

    public function test_a_rejected_acceptance_changes_nothing(): void
    {
        $sub = $this->as('client')->post('/cases', ['patientId' => 'pat_1024', 'doctorId' => 'doc_amina', 'serviceId' => 'svc_zirconia', 'shade' => 'A2', 'teeth' => [3], 'priority' => 'normal', 'instructions' => '']);
        $reception = $this->as('reception');
        $res = $reception->post("/cases/{$sub->json('id')}/status", ['status' => 'received', 'payment' => ['amount' => 9999, 'method' => 'bank_transfer']]);
        $res->assertStatus(422);
        $keys = array_keys($res->json('errors'));
        sort($keys);
        $this->assertSame(['payment.amount', 'payment.reference'], $keys);
        $row = DentalCase::with('invoice')->find($sub->json('id'));
        $this->assertSame(['submitted', null, null, null], [$row->status, $row->received_at, $row->due_at, $row->invoice]);
        $ok = $this->step($reception, $sub->json('id'), 'status', ['status' => 'received', 'payment' => ['amount' => 5, 'method' => 'cash']]);
        $this->assertSame([5, 'partial'], [$ok['invoice']['paid'], $ok['invoice']['status']]);
    }

    public static function actions(): array
    {
        return array_map(fn ($a) => [$a], array_combine(array_keys(Workflow::ACTIONS), array_keys(Workflow::ACTIONS)));
    }

    /** Server-side permission on every step, independent of the UI, checked against the shared rules. */
    #[DataProvider('actions')]
    public function test_each_action_is_allowed_exactly_for_the_roles_the_shared_rules_allow(string $action): void
    {
        $def = Workflow::ACTIONS[$action];
        $target = DentalCase::where('status', $def['from'][0])->orderByDesc('created_at')->first();
        if (in_array($action, ['resubmit', 'confirm_receipt'], true)) {
            $target->forceFill(['clinic_id' => 'cln_smile', 'doctor_id' => 'doc_amina'])->save();
        }
        $endpoint = Workflow::ACTION_ENDPOINT[$action];
        // A probe that fails validation: a permitted role gets 422 (or 200 when nothing is required), a forbidden one 403/404.
        $probe = match ($endpoint) {
            'assign' => ['technicianId' => '', 'note' => 'x'],
            'qc' => ['result' => $action === 'qc_pass' ? 'pass' : 'fail', 'issues' => [], 'notes' => ''],
            'rework' => ['note' => ''],
            'delivery' => ['status' => $def['to'], 'method' => 'lab_courier', 'courierName' => '', 'receivedBy' => ''],
            default => ['status' => $def['to'], 'note' => ''],
        };
        foreach (['reception', 'manager', 'technician', 'technician2', 'qc', 'delivery', 'client', 'admin'] as $who) {
            $c = $this->as($who);
            $me = $c->get('/auth/me')->json();
            $ref = ['status' => $target->status, 'clinicId' => $target->clinic_id, 'technicianId' => $target->technician_id];
            $expected = Workflow::canPerformAction($action, $ref, ['user' => $me['user'], 'permissions' => $me['permissions']]);
            DB::beginTransaction(); // a successful probe is undone before the next role tries
            $res = $c->post("/cases/{$target->id}/{$endpoint}", $probe);
            DB::rollBack();
            $msg = "{$who} {$action}: {$res->status()} {$res->getContent()}";
            $this->assertContains($res->status(), $expected ? [200, 422] : [403, 404], $msg);
        }
    }

    public function test_a_technician_cannot_see_or_move_another_technicians_case(): void
    {
        $other = DentalCase::where('status', 'in_production')->where('technician_id', '!=', 'tec_fatima')->first();
        $tech = $this->as('technician');
        $tech->get("/cases/{$other->id}")->assertStatus(404);
        $tech->post("/cases/{$other->id}/status", ['status' => 'quality_control'])->assertStatus(404);
    }

    public function test_sla_states_come_from_stored_timestamps_at_server_time(): void
    {
        $c = $this->createReceivedCase($this->as('reception'));
        $listed = fn (ApiClient $who, string $sla) => in_array($c['id'], array_column($who->get("/cases?sla={$sla}&perPage=200")->json('data'), 'id'), true);
        $this->assertTrue($listed($this->as('manager'), 'on_track'));

        $this->advanceMinutes(40 * 60);
        $m1 = $this->as('manager');
        $this->assertTrue($listed($m1, 'at_risk'));
        $this->assertFalse($listed($m1, 'overdue'));

        $this->advanceMinutes(9 * 60);
        $m2 = $this->as('manager');
        $this->assertTrue($listed($m2, 'overdue'));
        $overdueNow = DentalCase::whereIn('status', Workflow::IN_LAB_STATUSES)->where('due_at', '<=', now())->count();
        $this->assertSame($overdueNow, $m2->get('/cases/counts')->json('overdue'));
        $this->assertSame($overdueNow, $m2->get('/dashboard?period=today')->json('overdue'));

        // Delivered late: recorded as late.
        $this->step($m2, $c['id'], 'assign', ['technicianId' => 'tec_fatima']);
        $tech = $this->as('technician');
        $this->step($tech, $c['id'], 'status', ['status' => 'in_production']);
        $this->step($tech, $c['id'], 'status', ['status' => 'quality_control']);
        $this->step($this->as('qc'), $c['id'], 'qc', ['result' => 'pass', 'issues' => [], 'notes' => '']);
        $late = $this->step($this->as('delivery'), $c['id'], 'delivery', ['status' => 'delivered', 'method' => 'clinic_pickup', 'receivedBy' => 'Front desk']);
        $this->assertMatchesRegularExpression('/outside the 48-hour window/', end($late['history'])['note']);
    }
}
