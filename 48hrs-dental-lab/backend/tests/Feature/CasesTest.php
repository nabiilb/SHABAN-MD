<?php

namespace Tests\Feature;

use App\Models\CaseStatusHistory;
use App\Models\DentalCase;
use App\Models\Invoice;
use App\Models\Payment;
use Tests\Support\ApiClient;
use Tests\TestCase;

class CasesTest extends TestCase
{
    private function rows(ApiClient $c, string $qs): array
    {
        $res = $c->get('/cases?perPage=200&'.$qs);
        $this->assertSame(200, $res->status(), $qs.' '.$res->getContent());

        return $res->json('data');
    }

    /* ------------------------- create, read, update, delete ------------------------- */

    public function test_creates_with_server_side_pricing_case_number_and_a_new_patient(): void
    {
        $res = $this->as('reception')->post('/cases', [
            'newPatient' => ['name' => 'Test Patient', 'phone' => '+252 61 111 2222'],
            'doctorId' => 'doc_amina', 'clinicId' => 'cln_smile', 'serviceId' => 'svc_emax', 'shade' => 'A1',
            'teeth' => [9, 8, 8], 'priority' => 'urgent', 'instructions' => 'Rush',
            'total' => 1, // ignored: prices come from the catalogue and settings
        ]);
        $res->assertCreated()->assertJson(['teeth' => [8, 9], 'units' => 2, 'unitPrice' => 25, 'emergencyFee' => 10, 'total' => 60, 'priority' => 'urgent', 'status' => 'received']);
        $this->assertMatchesRegularExpression('/^DL-\d{4}-\d{5}$/', $res->json('caseNumber'));
        $this->assertSame('Test Patient', $res->json('patient.name'));
        $this->assertMatchesRegularExpression('/^PT-\d+$/', $res->json('patient.code'));
        $res->assertJson(['invoice' => ['total' => 60, 'paid' => 0, 'remaining' => 60, 'status' => 'unpaid']]);
        $this->assertCount(1, $res->json('history'));
        // The 48-hour clock starts at reception.
        $this->assertSame(self::NOW + 48 * 3_600_000, strtotime($res->json('dueAt')) * 1000);
    }

    public function test_missing_and_inconsistent_fields_are_422(): void
    {
        $reception = $this->as('reception');
        $empty = $reception->post('/cases', []);
        $empty->assertStatus(422);
        foreach (['doctorId', 'serviceId', 'shade', 'priority'] as $k) {
            $this->assertArrayHasKey($k, $empty->json('errors'));
        }
        $mismatch = $reception->post('/cases', ['patientId' => 'pat_1024', 'doctorId' => 'doc_layla', 'clinicId' => 'cln_smile', 'serviceId' => 'svc_zirconia', 'shade' => 'A2', 'teeth' => [], 'priority' => 'normal']);
        $mismatch->assertStatus(422);
        $this->assertMatchesRegularExpression('/does not belong/', $mismatch->json('errors.doctorId.0'));
        $this->assertMatchesRegularExpression('/at least one tooth/', $mismatch->json('errors.teeth.0'));
        $badTooth = $reception->post('/cases', ['patientId' => 'pat_1024', 'doctorId' => 'doc_amina', 'clinicId' => 'cln_smile', 'serviceId' => 'svc_zirconia', 'shade' => 'A2', 'teeth' => [33], 'priority' => 'normal']);
        $this->assertNotEmpty($badTooth->json()['errors']['teeth.0'] ?? null);
        $pastDue = $reception->post('/cases', ['patientId' => 'pat_1024', 'doctorId' => 'doc_amina', 'clinicId' => 'cln_smile', 'serviceId' => 'svc_zirconia', 'shade' => 'A2', 'teeth' => [3], 'priority' => 'normal', 'dueAt' => '2001-01-01T00:00:00Z']);
        $this->assertNotEmpty($pastDue->json('errors.dueAt'));
    }

    public function test_clients_can_only_submit_for_their_own_clinic(): void
    {
        $res = $this->as('client')->post('/cases', ['patientId' => 'pat_1025', 'doctorId' => 'doc_layla', 'clinicId' => 'cln_horizon', 'serviceId' => 'svc_zirconia', 'shade' => 'A2', 'teeth' => [3], 'priority' => 'normal', 'instructions' => '']);
        $res->assertStatus(422); // their clinic is forced; Dr. Layla is not at Smile
        $this->assertNotEmpty($res->json('errors.doctorId'));
    }

    public function test_client_submissions_wait_for_acceptance_before_the_clock_starts(): void
    {
        $res = $this->as('client')->post('/cases', ['patientId' => 'pat_1024', 'doctorId' => 'doc_amina', 'clinicId' => 'cln_smile', 'serviceId' => 'svc_zirconia', 'shade' => 'A2', 'teeth' => [3], 'priority' => 'normal', 'instructions' => '', 'receiveNow' => true]);
        $res->assertCreated()->assertJson(['status' => 'submitted', 'receivedAt' => null, 'dueAt' => null]);
        $this->assertNotNull($res->json('submittedAt'));
    }

    public function test_edits_open_cases_and_reprices_the_invoice_closed_cases_are_read_only(): void
    {
        $reception = $this->as('reception');
        $c = $this->createReceivedCase($reception);
        $res = $reception->patch("/cases/{$c['id']}", ['teeth' => [7, 8, 9], 'shade' => 'B1', 'priority' => 'urgent']);
        $res->assertOk()->assertJson(['teeth' => [7, 8, 9], 'shade' => 'B1', 'units' => 3, 'total' => 75, 'invoice' => ['total' => 75, 'remaining' => 75]]);
        $closed = DentalCase::where('status', 'completed')->first();
        $refused = $reception->patch("/cases/{$closed->id}", ['shade' => 'A1']);
        $refused->assertStatus(422);
        $this->assertMatchesRegularExpression('/Closed cases/', $refused->json('message'));
    }

    public function test_deletes_only_cases_without_payments_and_everything_linked_goes_with_it(): void
    {
        $admin = $this->as('admin');
        $c = $this->createReceivedCase($admin);
        $admin->delete("/cases/{$c['id']}")->assertNoContent();
        $this->assertNull(DentalCase::find($c['id']));
        $this->assertSame(0, Invoice::where('case_id', $c['id'])->count());
        $this->assertSame(0, CaseStatusHistory::where('case_id', $c['id'])->count());
        $paid = Payment::with('invoice')->first();
        $admin->delete("/cases/{$paid->invoice->case_id}")->assertStatus(422);
        $this->as('reception')->delete("/cases/{$paid->invoice->case_id}")->assertStatus(403);
    }

    public function test_missing_cases_404_notes_are_validated_and_stored_case_number_lookup(): void
    {
        $manager = $this->as('manager');
        $manager->get('/cases/cas_missing')->assertStatus(404)->assertExactJson(['message' => 'Resource not found.']);
        $c = DentalCase::where('status', 'in_production')->first();
        $manager->post("/cases/{$c->id}/notes", ['text' => '  '])->assertStatus(422);
        $res = $manager->post("/cases/{$c->id}/notes", ['text' => 'Check the margin']);
        $res->assertCreated();
        $notes = $res->json('notes');
        $this->assertSame(['text' => 'Check the margin', 'authorName' => 'Omar Farah'], array_intersect_key(end($notes), ['text' => 1, 'authorName' => 1]));
        $this->assertSame($c->id, $manager->get("/cases/{$c->case_number}")->json('id'));
    }

    /* ------------------------- list: filters, search, sort, scope ------------------------- */

    public function test_filters_by_status_priority_technician_doctor_clinic_and_case_type(): void
    {
        $admin = $this->as('admin');
        $all = $this->rows($admin, '');
        $this->assertCount(DentalCase::count(), $all);
        $checks = [
            'status=in_production&status=rework' => fn ($r) => in_array($r['status'], ['in_production', 'rework'], true),
            'priority=urgent' => fn ($r) => $r['priority'] === 'urgent',
            'technicianId=tec_fatima' => fn ($r) => $r['technicianId'] === 'tec_fatima',
            'doctorId=doc_amina' => fn ($r) => $r['doctorId'] === 'doc_amina',
            'clinicId=cln_banadir' => fn ($r) => $r['clinicId'] === 'cln_banadir',
            'openOnly=true' => fn ($r) => ! in_array($r['status'], ['delivered', 'completed', 'cancelled', 'rejected'], true),
        ];
        foreach ($checks as $qs => $check) {
            $rows = $this->rows($admin, $qs);
            $this->assertNotEmpty($rows, $qs);
            $this->assertTrue(collect($rows)->every($check), $qs);
            $this->assertLessThan(count($all), count($rows), $qs);
        }
        // Repeated keys and comma lists both select several statuses.
        $this->assertSame(count($this->rows($admin, 'status=in_production&status=rework')), count($this->rows($admin, 'status=in_production,rework')));
        $this->assertCount(DentalCase::where('case_type', 'denture')->count(), $this->rows($admin, 'caseType=denture'));
        $admin->get('/cases?status=bogus')->assertStatus(422);
    }

    public function test_date_ranges_on_received_date_and_on_the_deadline(): void
    {
        $admin = $this->as('admin');
        $fresh = $this->createReceivedCase($this->as('reception'));
        $today = self::labDay($fresh['receivedAt']);
        $recent = $this->rows($admin, "from={$today}&to={$today}");
        $this->assertContains($fresh['id'], array_column($recent, 'id'));
        $this->assertTrue(collect($recent)->every(fn ($r) => self::labDay($r['receivedAt'] ?? $r['createdAt']) === $today));
        $yesterday = self::labDay(strtotime($fresh['receivedAt']) * 1000 - 86_400_000);
        $this->assertNotContains($fresh['id'], array_column($this->rows($admin, "from={$yesterday}&to={$yesterday}"), 'id'));
        $dueDay = self::labDay($fresh['dueAt']);
        $due = $this->rows($admin, "dueFrom={$dueDay}&dueTo={$dueDay}");
        $this->assertContains($fresh['id'], array_column($due, 'id'));
        $this->assertTrue(collect($due)->every(fn ($r) => $r['dueAt'] && self::labDay($r['dueAt']) === $dueDay));
        $admin->get('/cases?from=2026-13-40')->assertStatus(422);
    }

    public function test_sla_and_payment_filters(): void
    {
        $admin = $this->as('admin');
        $overdue = $this->rows($admin, 'sla=overdue');
        $this->assertNotEmpty($overdue);
        $this->assertTrue(collect($overdue)->every(fn ($r) => strtotime($r['dueAt']) * 1000 <= self::NOW));
        foreach (['paid', 'partial', 'unpaid', 'overdue'] as $status) {
            $this->assertTrue(collect($this->rows($admin, "paymentStatus={$status}"))->every(fn ($r) => $r['paymentStatus'] === $status), $status);
        }
        $admin->get('/cases?sla=soon')->assertStatus(422);
    }

    public function test_search_by_case_number_patient_name_and_code_doctor_clinic(): void
    {
        $admin = $this->as('admin');
        $c = DentalCase::with('patient')->first();
        $this->assertSame([$c->id], array_column($this->rows($admin, 'search='.$c->case_number), 'id'));
        $this->assertTrue(collect($this->rows($admin, 'search='.rawurlencode($c->patient->name)))->every(fn ($r) => $r['patient']['name'] === $c->patient->name));
        $this->assertNotEmpty($this->rows($admin, 'search='.$c->patient->code));
        $this->assertTrue(collect($this->rows($admin, 'search=banadir'))->every(fn ($r) => $r['clinicId'] === 'cln_banadir'));
        // LIKE wildcards are matched literally, never as "anything".
        $this->assertSame([], $this->rows($admin, 'search=%25'));
        $this->assertSame([], $this->rows($admin, 'search=_'));
    }

    public function test_sorts_and_paginates(): void
    {
        $admin = $this->as('admin');
        $dues = array_values(array_filter(array_column($this->rows($admin, 'sort=dueAt&dir=asc'), 'dueAt')));
        $sorted = $dues;
        sort($sorted);
        $this->assertSame($sorted, $dues);
        $totals = array_column($this->rows($admin, 'sort=total&dir=desc'), 'total');
        $desc = $totals;
        rsort($desc);
        $this->assertSame($desc, $totals);
        $p1 = $admin->get('/cases?perPage=10&page=1');
        $p2 = $admin->get('/cases?perPage=10&page=2');
        $p1->assertJsonPath('meta', ['page' => 1, 'perPage' => 10, 'total' => DentalCase::count(), 'lastPage' => (int) ceil(DentalCase::count() / 10)]);
        $this->assertNotSame($p1->json('data.0.id'), $p2->json('data.0.id'));
        $beyond = $admin->get('/cases?perPage=10&page=999');
        $this->assertSame($beyond->json('meta.lastPage'), $beyond->json('meta.page'));
        $admin->get('/cases?sort=nonsense')->assertStatus(422);
    }

    public function test_row_scope_technicians_see_their_cases_clients_their_clinic_404_outside(): void
    {
        $mine = $this->rows($this->as('technician'), '');
        $this->assertCount(DentalCase::where('technician_id', 'tec_fatima')->count(), $mine);
        $this->assertTrue(collect($mine)->every(fn ($r) => $r['technicianId'] === 'tec_fatima'));
        $client = $this->as('client');
        $this->assertTrue(collect($this->rows($client, ''))->every(fn ($r) => $r['clinicId'] === 'cln_smile'));
        $other = DentalCase::where('clinic_id', '!=', 'cln_smile')->first();
        $client->get("/cases/{$other->id}")->assertStatus(404);
        $client->get("/cases/{$other->case_number}")->assertStatus(404);
        $this->assertSame(
            DentalCase::where('clinic_id', 'cln_smile')->whereIn('status', ['assigned', 'in_production', 'rework'])->count(),
            $client->get('/cases/counts')->json('inProduction'),
        );
    }

    public function test_users_without_cases_view_get_403(): void
    {
        $admin = $this->as('superAdmin');
        $role = collect($admin->get('/roles')->json())->firstWhere('key', 'delivery');
        $admin->put('/roles/delivery', ['permissions' => array_values(array_diff($role['permissions'], ['cases.view']))])->assertOk();
        $this->as('delivery')->get('/cases')->assertStatus(403)->assertExactJson(['message' => 'Access restricted.']);
    }
}
