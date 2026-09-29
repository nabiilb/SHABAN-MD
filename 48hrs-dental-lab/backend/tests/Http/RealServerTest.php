<?php

namespace Tests\Http;

use App\Models\CaseAssignment;
use App\Models\CaseStatusHistory;
use App\Models\DentalCase;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\QualityCheck;
use Illuminate\Support\Facades\DB;

class RealServerTest extends HttpTestCase
{
    private const PORT = 8391;

    private const LIMITED_PORT = 8392;

    private function as(string $email): HttpClient
    {
        $c = new HttpClient(self::server(self::PORT));
        $res = $c->login($email);
        $this->assertSame(200, $res['status'], $res['body']);

        return $c;
    }

    private function receivedCase(HttpClient $c): array
    {
        $res = $c->post('/api/cases', ['patientId' => 'pat_1024', 'doctorId' => 'doc_amina', 'clinicId' => 'cln_smile', 'serviceId' => 'svc_zirconia', 'shade' => 'A2', 'teeth' => [8, 9], 'priority' => 'normal', 'instructions' => '', 'receiveNow' => true]);
        $this->assertSame(201, $res['status'], $res['body']);

        return $res['json'];
    }

    /* ---------------------------------- CSRF ---------------------------------- */

    public function test_writes_need_the_csrf_token_from_the_xsrf_cookie(): void
    {
        $c = new HttpClient(self::server(self::PORT));
        $csrf = $c->get('/api/auth/csrf');
        $this->assertSame(204, $csrf['status']);
        $this->assertArrayHasKey('XSRF-TOKEN', $c->cookies);

        // Without the header, even sign-in is refused (419), and so is a wrong token.
        $c->sendXsrf = false;
        $res = $c->post('/api/auth/login', ['email' => 'hodan@48hrs.lab', 'password' => self::PASSWORD]);
        $this->assertSame(419, $res['status']);
        $this->assertSame(['message' => 'The page expired. Please try again.'], $res['json']);
        $this->assertSame(419, $c->request('POST', '/api/auth/login', ['email' => 'hodan@48hrs.lab', 'password' => self::PASSWORD], ['X-XSRF-TOKEN' => 'forged'])['status']);

        $c->sendXsrf = true;
        $this->assertSame(200, $c->post('/api/auth/login', ['email' => 'hodan@48hrs.lab', 'password' => self::PASSWORD])['status']);
        $c->sendXsrf = false;
        $this->assertSame(419, $c->post('/api/notifications/read-all')['status']);
        $c->sendXsrf = true;
        $this->assertSame(204, $c->post('/api/notifications/read-all')['status']);
        // Reads need no token.
        $c->sendXsrf = false;
        $this->assertSame(200, $c->get('/api/cases')['status']);
    }

    public function test_cookies_are_http_only_lax_and_the_api_answers_json_errors(): void
    {
        $c = new HttpClient(self::server(self::PORT));
        $c->get('/api/auth/csrf');
        $raw = [];
        $ch = curl_init(self::server(self::PORT).'/api/auth/csrf');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
        $head = (string) curl_exec($ch);
        preg_match_all('/^set-cookie:\s*(.+)$/im', $head, $raw);
        $session = collect($raw[1])->first(fn ($l) => ! str_starts_with($l, 'XSRF-TOKEN='));
        $this->assertMatchesRegularExpression('/httponly/i', $session);
        $this->assertMatchesRegularExpression('/samesite=lax/i', $session);
        $this->assertDoesNotMatchRegularExpression('/httponly/i', collect($raw[1])->first(fn ($l) => str_starts_with($l, 'XSRF-TOKEN=')));

        $this->assertSame(['message' => 'Resource not found.'], $c->get('/api/nope')['json']);
        $this->assertSame(401, $c->get('/api/cases')['status']);
        $health = $c->get('/api/health/ready');
        $this->assertSame(200, $health['status'], $health['body']);
    }

    /* ------------------------------- rate limiting ------------------------------- */

    public function test_after_auth_rate_limit_attempts_sign_in_is_refused_even_with_the_right_password(): void
    {
        $base = self::server(self::LIMITED_PORT, ['AUTH_RATE_LIMIT' => '5']);
        DB::table('cache')->delete();
        $c = new HttpClient($base);
        $c->get('/api/auth/csrf');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $c->post('/api/auth/login', ['email' => "nobody-{$i}@example.com", 'password' => 'Wrong-pass-1'])['status']);
        }
        $blocked = $c->post('/api/auth/login', ['email' => 'hodan@48hrs.lab', 'password' => self::PASSWORD]);
        $this->assertSame(429, $blocked['status']);
        $this->assertMatchesRegularExpression('/too many/i', $blocked['json']['message']);
        $this->assertArrayHasKey('retry-after', $blocked['headers']);
        // A spoofed X-Forwarded-For does not reset the limit (no trusted proxies configured).
        $this->assertSame(429, $c->request('POST', '/api/auth/login', ['email' => 'hodan@48hrs.lab', 'password' => self::PASSWORD], ['X-Forwarded-For' => '203.0.113.9'])['status']);
        // Only sign-in is limited: the rest of the API still answers.
        $this->assertSame(200, $c->get('/api/health')['status']);
        $this->assertSame(401, $c->get('/api/auth/me')['status']);
    }

    /* --------------------------- concurrent requests --------------------------- */

    public function test_two_people_moving_the_same_case_at_once_one_wins_the_other_gets_409(): void
    {
        $reception = $this->as('sagal@48hrs.lab');
        [$manager, $admin] = [$this->as('omar@48hrs.lab'), $this->as('hodan@48hrs.lab')];
        $c = $this->receivedCase($reception);
        $r = HttpClient::parallel([[$manager, 'POST', "/api/cases/{$c['id']}/status", ['status' => 'review']], [$admin, 'POST', "/api/cases/{$c['id']}/status", ['status' => 'review']]]);
        $statuses = [$r[0]['status'], $r[1]['status']];
        sort($statuses);
        $this->assertSame([200, 409], $statuses, json_encode($r));
        $this->assertSame(1, CaseStatusHistory::where('case_id', $c['id'])->where('to_status', 'review')->count());
    }

    public function test_two_managers_assigning_at_once_leave_exactly_one_open_assignment(): void
    {
        $reception = $this->as('sagal@48hrs.lab');
        [$m1, $m2] = [$this->as('omar@48hrs.lab'), $this->as('hodan@48hrs.lab')];
        $c = $this->receivedCase($reception);
        $r = HttpClient::parallel([[$m1, 'POST', "/api/cases/{$c['id']}/assign", ['technicianId' => 'tec_fatima']], [$m2, 'POST', "/api/cases/{$c['id']}/assign", ['technicianId' => 'tec_ali']]]);
        $this->assertContains(200, [$r[0]['status'], $r[1]['status']]);
        foreach ($r as $x) {
            $this->assertContains($x['status'], [200, 409], $x['body']);
        }
        $open = CaseAssignment::where('case_id', $c['id'])->whereNull('unassigned_at')->get();
        $this->assertCount(1, $open);
        $this->assertSame($open[0]->technician_id, DentalCase::find($c['id'])->technician_id);
    }

    public function test_two_qc_results_at_once_one_is_recorded_the_other_gets_409(): void
    {
        $reception = $this->as('sagal@48hrs.lab');
        $manager = $this->as('omar@48hrs.lab');
        $tech = $this->as('fatima@48hrs.lab');
        [$qc1, $qc2] = [$this->as('idil@48hrs.lab'), $this->as('hodan@48hrs.lab')];
        $c = $this->receivedCase($reception);
        $this->assertSame(200, $manager->post("/api/cases/{$c['id']}/assign", ['technicianId' => 'tec_fatima'])['status']);
        $this->assertSame(200, $tech->post("/api/cases/{$c['id']}/status", ['status' => 'in_production'])['status']);
        $this->assertSame(200, $tech->post("/api/cases/{$c['id']}/status", ['status' => 'quality_control'])['status']);
        $r = HttpClient::parallel([
            [$qc1, 'POST', "/api/cases/{$c['id']}/qc", ['result' => 'pass', 'issues' => [], 'notes' => '']],
            [$qc2, 'POST', "/api/cases/{$c['id']}/qc", ['result' => 'fail', 'issues' => ['shade'], 'notes' => 'Too light']],
        ]);
        $statuses = [$r[0]['status'], $r[1]['status']];
        sort($statuses);
        $this->assertSame([200, 409], $statuses, json_encode($r));
        $this->assertSame(1, QualityCheck::where('case_id', $c['id'])->count());
        $this->assertSame($r[0]['status'] === 200 ? 'ready' : 'rework', DentalCase::find($c['id'])->status);
    }

    public function test_concurrent_payments_can_never_exceed_the_total(): void
    {
        $reception = $this->as('sagal@48hrs.lab');
        $admin = $this->as('hodan@48hrs.lab');
        $c = $this->receivedCase($reception); // $40
        $r = HttpClient::parallel([
            [$reception, 'POST', '/api/payments', ['invoiceId' => $c['invoice']['id'], 'amount' => 30, 'method' => 'cash']],
            [$admin, 'POST', '/api/payments', ['invoiceId' => $c['invoice']['id'], 'amount' => 30, 'method' => 'cash']],
        ]);
        $statuses = [$r[0]['status'], $r[1]['status']];
        sort($statuses);
        $this->assertSame([201, 422], $statuses, json_encode($r));
        $this->assertEquals(30, Invoice::find($c['invoice']['id'])->amount_paid);
    }

    public function test_the_same_reference_submitted_twice_at_once_is_recorded_once(): void
    {
        $reception = $this->as('sagal@48hrs.lab');
        $admin = $this->as('hodan@48hrs.lab');
        $a = $this->receivedCase($reception);
        $b = $this->receivedCase($reception);
        foreach ([[$a, $a, 'EVC-DOUBLE-TAP'], [$a, $b, 'BANK-RACE-1']] as [$x, $y, $ref]) {
            $r = HttpClient::parallel([
                [$reception, 'POST', '/api/payments', ['invoiceId' => $x['invoice']['id'], 'amount' => 5, 'method' => 'mobile_money', 'reference' => $ref]],
                [$admin, 'POST', '/api/payments', ['invoiceId' => $y['invoice']['id'], 'amount' => 5, 'method' => 'mobile_money', 'reference' => $ref]],
            ]);
            $statuses = [$r[0]['status'], $r[1]['status']];
            sort($statuses);
            $this->assertSame([201, 422], $statuses, json_encode($r));
            $loser = $r[0]['status'] === 422 ? $r[0] : $r[1];
            $this->assertSame('Some fields need attention.', $loser['json']['message']);
            $this->assertMatchesRegularExpression('/^This reference is already recorded on INV-/', $loser['json']['errors']['reference'][0]);
            $this->assertSame(1, Payment::where('reference', $ref)->count());
        }
        // Ledger stays consistent: amount_paid equals the sum of payments.
        foreach ([$a, $b] as $x) {
            $inv = Invoice::with('payments')->find($x['invoice']['id']);
            $this->assertEquals($inv->payments->sum('amount'), $inv->amount_paid);
        }
        $this->assertEquals(10, Invoice::find($a['invoice']['id'])->amount_paid + Invoice::find($b['invoice']['id'])->amount_paid);
        $this->assertSame('received', DentalCase::find($a['id'])->status); // payments never move the workflow
    }

    public function test_recording_a_payment_waits_for_the_invoice_row_lock(): void
    {
        $reception = $this->as('sagal@48hrs.lab');
        $c = $this->receivedCase($reception);
        // Another transaction holds the invoice row (as a concurrent payment would).
        DB::beginTransaction();
        DB::table('invoices')->where('id', $c['invoice']['id'])->lockForUpdate()->first();
        $poll = $reception->start('POST', '/api/payments', ['invoiceId' => $c['invoice']['id'], 'amount' => 40, 'method' => 'cash']);
        $this->assertNull($poll(1.5), 'the payment must wait for the lock instead of reading a stale balance');
        DB::table('invoices')->where('id', $c['invoice']['id'])->update(['amount_paid' => 40]); // the other payment settled it
        DB::table('payments')->insert(['id' => strtolower((string) \Illuminate\Support\Str::ulid()), 'invoice_id' => $c['invoice']['id'], 'amount' => 40, 'method' => 'cash', 'reference' => null, 'notes' => '', 'received_by_id' => 'usr_sagal', 'paid_at' => now(), 'created_at' => now()]);
        DB::commit();
        $res = $poll(30);
        $this->assertSame(422, $res['status'], $res['body']); // it saw the committed balance: nothing left to pay
        $this->assertEquals(40, Invoice::find($c['invoice']['id'])->amount_paid);
    }

    public function test_many_parallel_payments_on_one_invoice_stop_exactly_at_the_total(): void
    {
        $reception = $this->as('sagal@48hrs.lab');
        $c = $this->receivedCase($reception); // $40
        $clients = [$reception, $this->as('hodan@48hrs.lab'), $this->as('khalid@48hrs.lab')];
        $reqs = [];
        for ($i = 0; $i < 8; $i++) {
            $reqs[] = [$clients[$i % 3], 'POST', '/api/payments', ['invoiceId' => $c['invoice']['id'], 'amount' => 7.5, 'method' => 'cash']];
        }
        $r = HttpClient::parallel($reqs);
        $ok = count(array_filter($r, fn ($x) => $x['status'] === 201));
        $this->assertSame(5, $ok, json_encode(array_column($r, 'status'))); // 5 × 7.5 = 37.5; a 6th would exceed 40
        $inv = Invoice::with('payments')->find($c['invoice']['id']);
        $this->assertEquals(37.5, $inv->amount_paid);
        $this->assertEquals($inv->payments->sum('amount'), $inv->amount_paid);
    }
}
