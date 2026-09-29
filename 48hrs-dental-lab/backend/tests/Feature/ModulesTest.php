<?php

namespace Tests\Feature;

use App\Domain\Workflow;
use App\Models\DentalCase;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\UserNotification;
use App\Services\DeadlineScanner;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    private const HOUR = 3_600_000;

    private static function today(): string
    {
        return self::labDay(self::NOW);
    }

    private static function daysAgo(int $n): string
    {
        return self::labDay(self::NOW - $n * 24 * self::HOUR);
    }

    private static function sortedDesc(array $v): bool
    {
        $s = $v;
        rsort($s);

        return $s === $v;
    }

    /* ------------------------------ directory ------------------------------ */

    public function test_patients_search_filter_sort_paginate_crud_and_delete_guard(): void
    {
        $reception = $this->as('reception');
        $this->assertSame(['PT-1024'], array_column($reception->get('/patients?search=PT-1024')->json('data'), 'code'));
        $this->assertNotEmpty($reception->get('/patients?search='.rawurlencode('61 9'))->json('data'));
        $this->assertTrue(collect($reception->get('/patients?clinicId=cln_aurora&perPage=100')->json('data'))->every(fn ($p) => $p['clinicId'] === 'cln_aurora'));
        $this->assertTrue(self::sortedDesc(array_column($reception->get('/patients?sort=caseCount&dir=desc&perPage=100')->json('data'), 'caseCount')));
        $lasts = array_values(array_filter(array_column($reception->get('/patients?sort=lastCaseAt&dir=desc&perPage=100')->json('data'), 'lastCaseAt')));
        $this->assertTrue(self::sortedDesc($lasts));
        $reception->get('/patients?perPage=10&page=2')->assertJson(['meta' => ['page' => 2, 'perPage' => 10, 'total' => Patient::count()]]);

        $created = $reception->post('/patients', ['name' => 'New Person', 'phone' => '+252 61 222 3333', 'email' => 'np@mail.so', 'gender' => 'female', 'dateOfBirth' => '1990-04-02', 'clinicId' => 'cln_smile']);
        $created->assertCreated()->assertJsonPath('dateOfBirth', '1990-04-02');
        $this->assertMatchesRegularExpression('/^PT-/', $created->json('code'));
        $id = $created->json('id');
        $this->assertNotEmpty($reception->post('/patients', ['name' => 'Dup', 'code' => 'pt-1024'])->json('errors.code'));
        $this->assertNotEmpty($reception->post('/patients', ['name' => 'Future', 'dateOfBirth' => '2999-01-01'])->json('errors.dateOfBirth'));
        $this->assertNotEmpty($reception->put("/patients/{$id}", ['name' => 'Renamed', 'phone' => 'bad'])->json('errors.phone'));
        $this->assertSame('Renamed', $reception->put("/patients/{$id}", ['name' => 'Renamed'])->json('name'));
        $reception->delete("/patients/{$id}")->assertStatus(403); // reception lacks patients.delete
        $admin = $this->as('admin');
        $admin->delete('/patients/pat_1024')->assertStatus(422);
        $admin->delete("/patients/{$id}")->assertNoContent();
        $detail = $admin->get('/patients/pat_1024')->json();
        $this->assertSame($detail['caseCount'], $detail['stats']['totalCases']);
    }

    public function test_doctors_and_clinics_filters_stats_uniqueness_and_delete_guards(): void
    {
        $admin = $this->as('admin');
        $this->assertSame(['Dr. Nasra Ahmed'], array_column($admin->get('/doctors?status=inactive')->json('data'), 'name'));
        $this->assertTrue(collect($admin->get('/doctors?clinicId=cln_smile')->json('data'))->every(fn ($d) => $d['clinicName'] === 'Smile Dental Clinic'));
        $this->assertTrue(self::sortedDesc(array_column($admin->get('/clinics?sort=caseCount&dir=desc')->json('data'), 'caseCount')));
        $this->assertSame(['Aurora Dental Studio'], array_column($admin->get('/clinics?search=aurora')->json('data'), 'name'));
        $this->assertNotEmpty($admin->post('/clinics', ['name' => 'smile dental clinic', 'phone' => '+252 61 000 0000'])->json('errors.name'));
        $admin->delete('/doctors/doc_amina')->assertStatus(422);
        $admin->delete('/clinics/cln_smile')->assertStatus(422);
        $k = $admin->post('/clinics', ['name' => 'Temp Clinic', 'phone' => '+252 61 000 1111'])->assertCreated();
        $d = $admin->post('/doctors', ['name' => 'Dr. Temp', 'clinicId' => $k->json('id'), 'phone' => '+252 61 000 2222'])->assertCreated();
        $admin->delete("/clinics/{$k->json('id')}")->assertStatus(422); // has a doctor
        $admin->delete("/doctors/{$d->json('id')}")->assertNoContent();
        $admin->delete("/clinics/{$k->json('id')}")->assertNoContent();
        $clinic = $admin->get('/clinics/cln_smile')->json();
        $this->assertCount($clinic['doctorCount'], $clinic['doctors']);
        $this->as('reception')->delete('/doctors/doc_yasin')->assertStatus(403);
        $this->assertSame(['cln_smile'], array_column($this->as('client')->get('/clinics')->json('data'), 'id'));
    }

    public function test_technicians_workload_own_profile_deactivation_and_delete_guards(): void
    {
        $manager = $this->as('manager');
        $this->assertTrue(self::sortedDesc(array_column($manager->get('/technicians?active=true&sort=activeCases&dir=desc')->json('data'), 'activeCases')));
        $this->assertSame(['Ahmed Hassan'], array_column($manager->get('/technicians?search=ceramics')->json('data'), 'name'));
        $tech = $this->as('technician');
        $own = $tech->get('/technicians/tec_fatima');
        $own->assertOk();
        $this->assertTrue(collect($own->json('activeCaseList'))->every(fn ($c) => $c['technicianId'] === 'tec_fatima'));
        $tech->get('/technicians/tec_ahmed')->assertStatus(403);
        $manager->put('/technicians/tec_fatima', ['name' => 'Fatima Nur', 'email' => 'fatima@48hrs.lab', 'phone' => '+252 61 800 2001', 'specialty' => 'Crown & bridge', 'active' => false])->assertStatus(422);
        $t = $manager->post('/technicians', ['name' => 'New Tech', 'email' => 'new.tech@48hrs.lab', 'phone' => '+252 61 000 0000', 'specialty' => 'Ceramics', 'active' => true])->assertCreated();
        $manager->delete("/technicians/{$t->json('id')}")->assertStatus(403); // lab manager: create/edit, not delete
        $admin = $this->as('admin');
        $admin->delete('/technicians/tec_fatima')->assertStatus(422);
        $admin->delete("/technicians/{$t->json('id')}")->assertNoContent();
    }

    /* ---------------------- quality control and deliveries ---------------------- */

    public function test_qc_checks_are_listed_with_results_filters_and_scope(): void
    {
        $failed = $this->as('qc')->get('/quality-control?result=failed&perPage=200')->json('data');
        $this->assertNotEmpty($failed);
        $this->assertTrue(collect($failed)->every(fn ($r) => $r['result'] === 'failed' && count($r['issues']) > 0 && $r['caseNumber']));
        $this->as('technician')->get('/quality-control')->assertStatus(403);
    }

    public function test_deliveries_are_listed_with_who_delivered_when_and_who_received(): void
    {
        $done = $this->as('delivery')->get('/deliveries?status=delivered&perPage=200')->json('data');
        $this->assertNotEmpty($done);
        $this->assertTrue(collect($done)->every(fn ($d) => $d['status'] === 'delivered' && $d['receivedBy'] && $d['recordedByName'] && $d['deliveredAt']));
    }

    /* ----------------------- notifications and deadline scan ----------------------- */

    public function test_workflow_events_notify_the_right_people_read_state_is_per_user(): void
    {
        $c = $this->createReceivedCase($this->as('reception'));
        $manager = $this->as('manager');
        $list = $manager->get('/notifications')->json();
        $note = collect($list['data'])->firstWhere('caseId', $c['id']);
        $this->assertSame(['case_received', $c['caseNumber'], null], [$note['type'], $note['caseNumber'], $note['readAt']]);
        $manager->post("/cases/{$c['id']}/assign", ['technicianId' => 'tec_fatima'])->assertOk();
        $techList = $this->as('technician')->get('/notifications?unreadOnly=true')->json();
        $this->assertSame(['case_assigned', $c['id']], [$techList['data'][0]['type'], $techList['data'][0]['caseId']]);
        $this->assertTrue(collect($techList['data'])->every(fn ($n) => $n['readAt'] === null));
        // The actor is never notified of their own step.
        $this->assertFalse(collect($manager->get('/notifications?perPage=100')->json('data'))->contains(fn ($n) => $n['type'] === 'case_assigned' && $n['caseId'] === $c['id']));

        $manager->post("/notifications/{$note['id']}/read")->assertNoContent();
        $this->assertSame($list['unreadCount'] - 1, $manager->get('/notifications')->json('unreadCount'));
        $this->as('technician')->post("/notifications/{$note['id']}/read")->assertStatus(404);
        $manager->post('/notifications/read-all')->assertNoContent();
        $this->assertSame(0, $manager->get('/notifications')->json('unreadCount'));
        $this->assertSame(0, $manager->get('/cases/counts')->json('unreadNotifications'));
    }

    public function test_deadline_alerts_are_raised_once_per_case_whatever_the_number_of_runs(): void
    {
        $c = $this->createReceivedCase($this->as('reception'));
        $scanner = new DeadlineScanner;
        $scanner->run(); // settle the seeded board
        $count = fn (string $type) => UserNotification::where('case_id', $c['id'])->where('type', $type)->count();

        $r1 = $scanner->run(self::NOW + 40 * self::HOUR);
        $this->assertGreaterThanOrEqual(1, $r1['atRisk']);
        $atRisk = $count('deadline_approaching');
        $this->assertGreaterThan(0, $atRisk);
        $scanner->run(self::NOW + 41 * self::HOUR);
        $this->assertSame($atRisk, $count('deadline_approaching'));

        $scanner->run(self::NOW + 49 * self::HOUR);
        $scanner->run(self::NOW + 49 * self::HOUR);
        $overdue = $count('case_overdue');
        $this->assertGreaterThan(0, $overdue);
        $scanner->run(self::NOW + 60 * self::HOUR);
        $this->assertSame($overdue, $count('case_overdue'));
        $row = DentalCase::find($c['id']);
        $this->assertNotNull($row->at_risk_notified_at);
        $this->assertNotNull($row->overdue_notified_at);
        $roles = UserNotification::where('case_id', $c['id'])->where('type', 'case_overdue')->join('users', 'users.id', '=', 'notifications.user_id')->distinct()->pluck('users.role_key')->sort()->values()->all();
        $this->assertSame(['admin', 'lab_manager', 'reception'], $roles);
    }

    public function test_the_scheduled_job_runs_the_scan_through_the_queue(): void
    {
        $this->createReceivedCase($this->as('reception'));
        (new DeadlineScanner)->run();
        $this->advanceMinutes(49 * 60);
        $before = UserNotification::where('type', 'case_overdue')->count();
        dispatch(new \App\Jobs\ScanDeadlines); // QUEUE_CONNECTION=sync in tests
        $this->assertGreaterThan($before, UserNotification::where('type', 'case_overdue')->count());
        $this->artisan('lab:scan-deadlines')->expectsOutputToContain('0 overdue')->assertSuccessful();
    }

    /* ---------------------------- dashboard, reports, search ---------------------------- */

    public function test_dashboard_kpis_come_from_the_database_money_only_with_reports_financial(): void
    {
        $admin = $this->as('admin');
        $d = $admin->get('/dashboard?period=30d')->json();
        $inLab = Workflow::IN_LAB_STATUSES;
        $this->assertSame(DentalCase::whereIn('status', $inLab)->count(), $d['activeCases']);
        $this->assertSame(DentalCase::where('status', 'quality_control')->count(), $d['pendingQc']);
        $this->assertSame(DentalCase::whereIn('status', ['delivered', 'completed'])->count(), $d['completed']);
        $this->assertSame(DentalCase::whereIn('status', $inLab)->where('due_at', '<=', now())->count(), $d['overdue']);
        $this->assertGreaterThan(0, $d['revenue']);
        $this->assertCount(6, $d['revenueByMonth']);
        $this->assertCount(14, $d['last14Days']);
        $today = $admin->get('/dashboard?period=today')->json();
        $this->assertLessThanOrEqual($d['newCases'], $today['newCases']);
        $this->assertSame(self::today(), $today['periodStart']);
        $m = $this->as('manager')->get('/dashboard')->json();
        $this->assertSame([null, null, null, null], [$m['revenue'], $m['collected'], $m['outstanding'], $m['revenueByMonth']]);
        $this->assertSame(DentalCase::where('technician_id', 'tec_fatima')->whereIn('status', $inLab)->count(), $this->as('technician')->get('/dashboard')->json('activeCases'));
    }

    public function test_reports_honour_every_filter_and_financial_needs_reports_financial(): void
    {
        $admin = $this->as('admin');
        $range = 'from='.self::daysAgo(95).'&to='.self::today();
        $all = $admin->get("/reports/cases?{$range}")->json();
        $this->assertGreaterThan(40, $all['totals']['cases']);
        $this->assertCount(96, $all['daily']);
        $this->assertSame(['Fatima Nur'], array_column($admin->get("/reports/technicians?{$range}&technicianId=tec_fatima")->json('technicians'), 'name'));
        $this->assertSame(['Banadir Dental Centre'], array_column($admin->get("/reports/clinics?{$range}&clinicId=cln_banadir")->json('clinics'), 'name'));
        $this->assertSame(['in_production'], array_column($admin->get("/reports/cases?{$range}&status=in_production")->json('byStatus'), 'status'));
        $this->assertSame(['denture'], array_column($admin->get("/reports/cases?{$range}&caseType=denture")->json('byCaseType'), 'caseType'));
        $this->assertLessThan($all['totals']['cases'], $admin->get("/reports/cases?{$range}&doctorId=doc_amina")->json('totals.cases'));
        $this->assertCount(5, $admin->get("/reports/production?{$range}")->json('stages'));
        $money = $admin->get("/reports/financial?{$range}")->json('totals');
        $this->assertGreaterThan(0, $money['revenue']);
        $this->assertEqualsWithDelta($money['collected'] + $money['outstanding'], $money['revenue'], 0.5);

        $manager = $this->as('manager');
        $manager->get("/reports/cases?{$range}")->assertOk();
        $manager->get("/reports/financial?{$range}")->assertStatus(403);
        $admin->get('/reports/cases?from='.self::today().'&to='.self::daysAgo(3))->assertStatus(422);
        $admin->get('/reports/cases')->assertStatus(422);
        $this->as('reception')->get("/reports/cases?{$range}")->assertStatus(403);
    }

    public function test_global_search_finds_cases_patients_doctors_clinics_phones_and_invoices_within_scope(): void
    {
        $admin = $this->as('superAdmin');
        $c = DentalCase::where('status', 'in_production')->first();
        $inv = Invoice::first();
        $hit = function (string $q, string $type, string $href) use ($admin) {
            $res = $admin->get('/search?q='.rawurlencode($q))->json();
            $this->assertTrue(collect($res)->contains(fn ($h) => $h['type'] === $type && preg_match($href, $h['href'])), "{$q} → {$type}");
        };
        $hit($c->case_number, 'case', "#^/cases/{$c->id}$#");
        $hit('Hodan Jama', 'patient', '#^/patients/pat_#');
        $hit('Hibo', 'doctor', '#^/doctors/doc_hibo$#');
        $hit('Banadir', 'clinic', '#^/clinics/cln_banadir$#');
        $hit('700 1004', 'doctor', '#^/doctors/doc_abdirahman$#');
        $hit($inv->invoice_number, 'invoice', "#^/invoices/{$inv->id}$#");
        $this->assertSame([], $admin->get('/search?q=a')->json());
        $other = DentalCase::where('clinic_id', '!=', 'cln_smile')->first();
        $this->assertSame([], $this->as('client')->get("/search?q={$other->case_number}")->json());
    }

    /* ---------------------------------- administration ---------------------------------- */

    public function test_users_create_with_policy_role_rules_self_protection_and_history_guard(): void
    {
        $admin = $this->as('admin');
        $this->assertNotEmpty($admin->post('/users', ['name' => 'Weak', 'email' => 'weak@48hrs.lab', 'role' => 'reception', 'password' => 'short'])->json('errors.password'));
        $this->assertNotEmpty($admin->post('/users', ['name' => 'Dup', 'email' => self::USERS['reception'], 'role' => 'reception', 'password' => 'GoodPass123'])->json('errors.email'));
        $this->assertNotEmpty($admin->post('/users', ['name' => 'Boss', 'email' => 'boss@48hrs.lab', 'role' => 'super_admin', 'password' => 'GoodPass123'])->json('errors.role'));
        $this->assertNotEmpty($admin->post('/users', ['name' => 'Clinic', 'email' => 'c@x.so', 'role' => 'client', 'password' => 'GoodPass123'])->json('errors.clinicId'));

        $created = $admin->post('/users', ['name' => 'New Tech', 'email' => 'newtech@48hrs.lab', 'role' => 'technician', 'password' => 'GoodPass123']);
        $created->assertCreated();
        $this->assertNotEmpty($created->json('technicianId')); // a technician profile was created and linked
        $this->assertSame('technician', $this->as('newtech@48hrs.lab', 'GoodPass123')->get('/auth/me')->json('user.role'));

        $self = $admin->get('/auth/me')->json('user');
        $admin->patch("/users/{$self['id']}/status", ['active' => false])->assertStatus(422);
        $this->assertNotEmpty($admin->put("/users/{$self['id']}", [...$self, 'role' => 'reception'])->json('errors.role'));
        $admin->put('/users/usr_khalid', ['name' => 'Khalid Aden', 'email' => 'khalid@48hrs.lab', 'role' => 'super_admin', 'active' => true])->assertStatus(403);
        $admin->delete('/users/usr_sagal')->assertStatus(422); // has case history
        $admin->delete("/users/{$created->json('id')}")->assertNoContent();
        $this->as('reception')->get('/users')->assertStatus(403);
    }

    public function test_roles_locked_super_admin_unknown_permissions_ignored_catalogue_from_the_database(): void
    {
        $root = $this->as('superAdmin');
        $root->put('/roles/super_admin', ['permissions' => []])->assertStatus(422);
        $perms = $root->put('/roles/qc', ['permissions' => ['qc.view', 'qc.perform', 'cases.view', 'made.up']])->json('permissions');
        sort($perms);
        $this->assertSame(['cases.view', 'qc.perform', 'qc.view'], $perms);
        $keys = array_column($root->get('/permissions')->json(), 'key');
        foreach (['cases.update_status', 'doctors.delete', 'technicians.create', 'reports.financial', 'roles.manage', 'settings.manage'] as $k) {
            $this->assertContains($k, $keys);
        }
        $this->as('admin')->put('/roles/qc', ['permissions' => []])->assertStatus(403); // admin lacks roles.manage
    }

    public function test_services_settings_and_the_activity_log(): void
    {
        $admin = $this->as('admin');
        $svc = $admin->post('/services', ['name' => 'Inlay', 'caseType' => 'crown', 'unitMode' => 'tooth', 'unitPrice' => 30, 'defaultMaterial' => 'Composite', 'active' => true])->assertCreated();
        $this->assertNotEmpty($admin->post('/services', ['name' => 'X', 'caseType' => 'crown', 'unitMode' => 'tooth', 'unitPrice' => -1, 'defaultMaterial' => 'Y'])->json('errors.unitPrice'));
        $admin->delete('/services/svc_zirconia')->assertStatus(422);
        $admin->delete("/services/{$svc->json('id')}")->assertNoContent();

        $settings = $admin->get('/settings')->json();
        $this->assertNotEmpty($admin->put('/settings', [...$settings, 'criticalHours' => 20, 'atRiskHours' => 12])->json('errors.criticalHours'));
        $this->assertNotEmpty($admin->put('/settings', [...$settings, 'currency' => 'dollars'])->json('errors.currency'));
        $this->assertSame(72, $admin->put('/settings', [...$settings, 'slaHours' => 72])->json('slaHours'));
        $reception = $this->as('reception');
        $c = $this->createReceivedCase($reception);
        $this->assertSame(72 * self::HOUR, (strtotime($c['dueAt']) - strtotime($c['receivedAt'])) * 1000);
        $reception->put('/settings', $settings)->assertStatus(403);

        $this->advanceMinutes(1);
        $admin->post('/notifications/read-all');
        $activity = $admin->get('/activity?perPage=50')->json('data');
        $this->assertGreaterThanOrEqual(end($activity)['createdAt'], $activity[0]['createdAt']);
        $caseFeed = $admin->get("/activity?subjectType=case&search={$c['caseNumber']}")->json('data');
        $this->assertTrue(collect($caseFeed)->contains(fn ($a) => str_starts_with($a['description'], 'Created as received')));
        $reception->get('/activity')->assertStatus(403);
    }
}
