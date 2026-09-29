<?php

namespace Tests\Feature;

use App\Models\CaseAttachment;
use App\Models\CaseStatusHistory;
use App\Models\DentalCase;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\CaseFiles;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cross-cutting guarantees: the route permission matrix for every role, request
 * tampering, upload limits and file access, duplicate payments and all-or-nothing
 * rollback. (Races between concurrent requests: tests/Http/ConcurrencyTest.)
 */
class SecurityTest extends TestCase
{
    private static function png(): string
    {
        return hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da6300010000050001');
    }

    /** [method, path, body, the permission(s) the route requires (all), statuses when allowed] */
    private const PROBES = [
        ['GET', '/dashboard', null, ['dashboard.view'], [200]],
        ['GET', '/cases', null, ['cases.view'], [200]],
        ['GET', '/reports/cases?from=2026-01-01&to=2026-12-31', null, ['reports.view'], [200]],
        ['GET', '/reports/production?from=2026-01-01&to=2026-12-31', null, ['reports.view'], [200]],
        ['GET', '/reports/financial?from=2026-01-01&to=2026-12-31', null, ['reports.view', 'reports.financial'], [200]],
        ['GET', '/invoices', null, ['invoices.view'], [200]],
        ['GET', '/payments', null, ['payments.view'], [200]],
        ['POST', '/payments', [], ['payments.record'], [422]],
        ['POST', '/invoices', [], ['payments.record'], [422]],
        ['GET', '/quality-control', null, ['qc.view'], [200]],
        ['GET', '/deliveries', null, ['delivery.view'], [200]],
        ['GET', '/users', null, ['users.view'], [200]],
        ['POST', '/users', [], ['users.manage'], [422]],
        ['PUT', '/roles/delivery', ['permissions' => 'not-a-list'], ['roles.manage'], [422]],
        ['POST', '/services', [], ['services.manage'], [422]],
        ['GET', '/activity', null, ['audit.view'], [200]],
        ['POST', '/patients', [], ['patients.create'], [422]],
        ['DELETE', '/cases/case_does_not_exist', null, ['cases.delete'], [404]],
        ['POST', '/clinics', [], ['clinics.create'], [422]],
        ['POST', '/technicians', [], ['technicians.create'], [422]],
        ['PUT', '/settings', [], ['settings.manage'], [422]],
    ];

    public static function roles(): array
    {
        return collect(self::USERS)->except(['disabledClient', 'technician2'])->mapWithKeys(fn ($e, $k) => [$k => [$k]])->all();
    }

    #[DataProvider('roles')]
    public function test_rbac_matrix_allowed_exactly_where_the_role_holds_the_permission(string $role): void
    {
        $c = $this->as($role);
        $perms = $c->get('/auth/me')->json('permissions');
        $allowedSomewhere = $deniedSomewhere = false;
        foreach (self::PROBES as [$method, $path, $body, $needs, $allowed]) {
            $res = $c->request($method, $path, $body);
            $expected = ! array_diff($needs, $perms);
            $label = "{$role} {$method} {$path} → {$res->status()} {$res->getContent()}";
            if ($expected) {
                $this->assertContains($res->status(), $allowed, $label);
                $allowedSomewhere = true;
            } else {
                $this->assertSame(403, $res->status(), $label);
                $this->assertSame(['message' => 'Access restricted.'], $res->json(), $label);
                $deniedSomewhere = true;
            }
        }
        $this->assertTrue($role === 'superAdmin' ? ! $deniedSomewhere : ($allowedSomewhere && $deniedSomewhere), "{$role}: matrix is not trivial");
    }

    public function test_the_matrix_is_not_trivially_all_allowed_or_all_denied(): void
    {
        $perms = fn (string $who) => $this->as($who)->get('/auth/me')->json('permissions');
        $superAdmin = $perms('superAdmin');
        foreach (['users.manage', 'roles.manage', 'reports.financial', 'payments.record', 'audit.view'] as $p) {
            $this->assertContains($p, $superAdmin);
        }
        foreach (['client', 'technician'] as $who) {
            $theirs = $perms($who);
            foreach (['users.view', 'payments.record', 'reports.financial', 'audit.view', 'cases.view_all'] as $p) {
                $this->assertNotContains($p, $theirs, "{$who} {$p}");
            }
        }
    }

    public function test_every_protected_endpoint_answers_401_without_a_session(): void
    {
        foreach (['/auth/me', '/cases', '/dashboard', '/invoices', '/payments', '/reports/financial', '/users', '/search?q=a', '/notifications', '/activity', '/settings', '/roles'] as $path) {
            $this->assertSame(401, $this->client()->get($path)->status(), $path);
        }
    }

    /* ------------------------------- tampering ------------------------------- */

    public function test_a_client_cannot_widen_its_scope_with_query_parameters(): void
    {
        $client = $this->as('client');
        $this->assertTrue(collect($client->get('/cases?clinicId=cln_banadir&perPage=100')->json('data'))->every(fn ($r) => $r['clinicId'] === 'cln_smile'));
        $invoices = $client->get('/invoices?clinicId=cln_banadir');
        if ($invoices->status() === 200) {
            $this->assertTrue(collect($invoices->json('data'))->every(fn ($r) => $r['clinicId'] === 'cln_smile'));
        } else {
            $invoices->assertStatus(403);
        }
        $this->assertTrue(collect($client->get('/patients?clinicId=cln_banadir&perPage=100')->json('data') ?? [])->every(fn ($p) => ($p['clinicId'] ?? 'cln_smile') === 'cln_smile'));
    }

    public function test_server_owned_fields_in_the_body_are_ignored(): void
    {
        $res = $this->as('client')->post('/cases', [
            'patientId' => 'pat_1024', 'doctorId' => 'doc_amina', 'serviceId' => 'svc_zirconia', 'shade' => 'A2', 'teeth' => [3], 'priority' => 'normal', 'instructions' => '',
            'status' => 'delivered', 'total' => 0, 'unitPrice' => 0, 'receivedAt' => '2020-01-01T00:00:00Z', 'dueAt' => '2099-01-01T00:00:00Z', 'clinicId' => 'cln_banadir', 'technicianId' => 'tec_fatima',
        ]);
        $res->assertCreated();
        $row = DentalCase::find($res->json('id'));
        $this->assertSame(['submitted', null, null, 'cln_smile', null], [$row->status, $row->received_at, $row->due_at, $row->clinic_id, $row->technician_id]);
        $this->assertGreaterThan(0, $res->json('total'));
    }

    public function test_privilege_escalation_through_the_users_api_is_refused(): void
    {
        $reception = $this->as('reception');
        $me = $reception->get('/auth/me')->json('user');
        $reception->put("/users/{$me['id']}", ['name' => $me['name'], 'email' => $me['email'], 'role' => 'super_admin', 'active' => true])->assertStatus(403);
        $grant = $this->as('admin')->post('/users', ['name' => 'Eve', 'email' => 'eve@48hrs.lab', 'role' => 'super_admin', 'password' => 'Long-enough-pass-9', 'active' => true]);
        $grant->assertStatus(422);
        $this->assertNotEmpty($grant->json('errors.role'));
        $this->assertSame(0, User::where('email', 'eve@48hrs.lab')->count());
    }

    public function test_a_technician_cannot_reassign_or_edit_a_case_by_sending_the_fields_directly(): void
    {
        $tech = $this->as('technician');
        $own = DentalCase::where('technician_id', 'tec_fatima')->where('status', 'in_production')->first();
        $own->forceFill(['status' => 'assigned'])->save(); // a status where assigning is a valid step
        $tech->patch("/cases/{$own->id}", ['technicianId' => 'tec_ali'])->assertStatus(403);
        $tech->post("/cases/{$own->id}/assign", ['technicianId' => 'tec_ali'])->assertStatus(403);
        $this->assertSame('tec_fatima', $own->fresh()->technician_id);
    }

    public function test_the_payment_actor_is_the_session_user_never_a_body_field(): void
    {
        $reception = $this->as('reception');
        $c = $this->createReceivedCase($reception);
        $res = $reception->post('/payments', ['invoiceId' => $c['invoice']['id'], 'amount' => 5, 'method' => 'cash', 'receivedById' => 'usr_khalid', 'receivedByName' => 'Khalid']);
        $res->assertCreated();
        $this->assertSame('Sagal Warsame', $res->json('payments.0.receivedByName'));
    }

    public function test_sql_injection_attempts_are_plain_data(): void
    {
        $admin = $this->as('admin');
        $count = DentalCase::count();
        foreach (["' OR 1=1 --", "x'); DROP TABLE cases; --", '%\' UNION SELECT password FROM users --'] as $q) {
            $admin->get('/cases?search='.rawurlencode($q))->assertOk()->assertJsonPath('meta.total', 0);
            $admin->get('/search?q='.rawurlencode($q))->assertOk();
            $admin->get('/cases?sort='.rawurlencode($q))->assertStatus(422);
        }
        $this->assertSame($count, DentalCase::count());
    }

    public function test_errors_never_leak_internals(): void
    {
        config(['app.debug' => false]);
        $admin = $this->as('admin');
        $res = $admin->request('POST', '/clinics', '{bad json');
        $res->assertStatus(400);
        $this->assertSame(['message'], array_keys($res->json()));
        $wrongMethod = $admin->request('PATCH', '/dashboard');
        $this->assertContains($wrongMethod->status(), [404, 405]);
        $this->assertSame(['message'], array_keys($wrongMethod->json()));
        foreach ([$res] as $r) {
            $this->assertStringNotContainsString('vendor/', $r->getContent());
        }
    }

    public function test_responses_carry_security_headers_and_are_not_cached(): void
    {
        $res = $this->as('admin')->get('/cases');
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertNotNull($res->headers->get('X-Server-Time'));
    }

    /* --------------------------------- files --------------------------------- */

    public function test_oversized_uploads_are_refused_and_nothing_is_stored(): void
    {
        $c = DentalCase::where('status', 'in_production')->first();
        $big = "solid big\n".str_repeat(' ', 1024 * 1024 + 10)."endsolid big\n";
        $res = $this->as('reception')->upload("/cases/{$c->id}/attachments", UploadedFile::fake()->createWithContent('big.stl', $big));
        $res->assertStatus(422);
        $this->assertMatchesRegularExpression('/1 MB/', $res->json('errors.file.0'));
        $this->assertSame(0, CaseAttachment::where('case_id', $c->id)->where('name', 'big.stl')->count());
    }

    public function test_no_session_means_no_upload_or_download_and_storage_paths_are_never_served(): void
    {
        $reception = $this->as('reception');
        $c = DentalCase::where('status', 'in_production')->first();
        $up = $reception->upload("/cases/{$c->id}/attachments", UploadedFile::fake()->createWithContent('x.png', self::png()))->assertCreated();
        $key = CaseAttachment::find($up->json('id'))->storage_key;
        $this->client()->upload("/cases/{$c->id}/attachments", UploadedFile::fake()->createWithContent('y.png', self::png()))->assertStatus(401);
        $this->client()->get("/cases/{$c->id}/attachments/{$up->json('id')}/download")->assertStatus(401);
        foreach (["/storage/{$key}", "/uploads/{$key}", "/storage/app/private/cases/{$key}", "/{$key}"] as $guess) {
            $this->assertSame(404, $reception->get($guess)->status(), $guess); // under /api
            $this->assertSame(404, $this->get($guess)->status(), $guess);        // outside /api
        }
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR.'public', CaseFiles::disk()->path(''));
        $other = DentalCase::where('id', '!=', $c->id)->first();
        $reception->get("/cases/{$other->id}/attachments/{$up->json('id')}/download")->assertStatus(404);
    }

    public function test_path_traversal_in_the_ids_is_harmless(): void
    {
        $reception = $this->as('reception');
        $c = DentalCase::where('status', 'in_production')->first();
        foreach (['..%2F..%2F..%2Fetc%2Fpasswd', '%2e%2e%2f%2e%2e%2f.env'] as $id) {
            $this->assertSame(404, $reception->get("/cases/{$c->id}/attachments/{$id}/download")->status(), $id);
        }
    }

    public function test_stl_ascii_and_binary_are_served_back_byte_for_byte_as_model_stl(): void
    {
        $reception = $this->as('reception');
        $c = DentalCase::where('status', 'in_production')->first();
        $binary = str_pad('binary stl header', 80, "\0").pack('V', 1).str_repeat("\0", 50);
        foreach (['ascii.stl' => "solid t\nendsolid t\n", 'binary.stl' => $binary] as $name => $bytes) {
            $up = $reception->upload("/cases/{$c->id}/attachments", UploadedFile::fake()->createWithContent($name, $bytes));
            $up->assertCreated()->assertJson(['extension' => 'stl', 'mimeType' => 'model/stl', 'category' => 'scan', 'size' => strlen($bytes)]);
            $dl = $reception->get("/cases/{$c->id}/attachments/{$up->json('id')}/download");
            $this->assertSame($bytes, $dl->streamedContent());
            $this->assertSame('model/stl', $dl->headers->get('Content-Type'));
        }
    }

    public function test_the_client_declared_mime_type_is_never_trusted(): void
    {
        $reception = $this->as('reception');
        $c = DentalCase::where('status', 'in_production')->first();
        $png = $reception->upload("/cases/{$c->id}/attachments", UploadedFile::fake()->createWithContent('photo.png', self::png())->mimeType('text/html'));
        $png->assertCreated()->assertJsonPath('mimeType', 'image/png');
        $dl = $reception->get("/cases/{$c->id}/attachments/{$png->json('id')}/download");
        $this->assertSame('image/png', $dl->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $dl->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $dl->headers->get('X-Content-Type-Options'));
        $reception->upload("/cases/{$c->id}/attachments", UploadedFile::fake()->createWithContent('scan.stl', '<!DOCTYPE html><script>alert(1)</script>')->mimeType('model/stl'))->assertStatus(422);
        $reception->upload("/cases/{$c->id}/attachments", UploadedFile::fake()->createWithContent('upper.stl', "MZ\x90\x00\x03")->mimeType('model/stl'))->assertStatus(422);
    }

    public function test_extensions_outside_the_allow_list_are_refused_including_double_extensions(): void
    {
        $reception = $this->as('reception');
        $c = DentalCase::where('status', 'in_production')->first();
        foreach (['scan.stl.exe', 'page.html', 'image.svg', 'script.js', 'archive.zip', 'noextension', 'shell.php', 'x.phtml', '.htaccess'] as $name) {
            $res = $reception->upload("/cases/{$c->id}/attachments", UploadedFile::fake()->createWithContent($name, "solid x\nendsolid x\n"));
            $this->assertSame(422, $res->status(), $name);
            $this->assertMatchesRegularExpression('/not accepted/', $res->json('errors.file.0'), $name);
        }
        $this->assertSame(0, CaseAttachment::where('case_id', $c->id)->whereIn('name', ['scan.stl.exe', 'page.html', 'image.svg', 'shell.php'])->count());
    }

    public function test_users_outside_the_case_cannot_list_download_or_delete_its_files(): void
    {
        $reception = $this->as('reception');
        $c = DentalCase::where('status', 'in_production')->where('technician_id', '!=', 'tec_fatima')->where('clinic_id', '!=', 'cln_smile')->first();
        $up = $reception->upload("/cases/{$c->id}/attachments", UploadedFile::fake()->createWithContent('private.png', self::png()))->assertCreated();
        foreach ([$this->as('technician'), $this->as('client')] as $outsider) {
            $outsider->get("/cases/{$c->id}")->assertStatus(404);
            $outsider->get("/cases/{$c->id}/attachments/{$up->json('id')}/download")->assertStatus(404);
            $this->assertContains($outsider->delete("/cases/{$c->id}/attachments/{$up->json('id')}")->status(), [403, 404]);
        }
        $this->assertSame(1, CaseAttachment::whereKey($up->json('id'))->count());
    }

    /* ------------------------------- payments ------------------------------- */

    public function test_a_transaction_reference_can_only_be_recorded_once_and_the_balance_is_untouched(): void
    {
        $reception = $this->as('reception');
        $a = $this->createReceivedCase($reception);
        $b = $this->createReceivedCase($reception);
        $reception->post('/payments', ['invoiceId' => $a['invoice']['id'], 'amount' => 10, 'method' => 'bank_transfer', 'reference' => 'TRX-001'])->assertCreated();
        $dup = $reception->post('/payments', ['invoiceId' => $b['invoice']['id'], 'amount' => 10, 'method' => 'bank_transfer', 'reference' => ' TRX-001 ']);
        $dup->assertStatus(422);
        $this->assertMatchesRegularExpression('/already recorded/', $dup->json('errors.reference.0'));
        $inv = Invoice::with('payments')->find($b['invoice']['id']);
        $this->assertEquals(0, $inv->amount_paid);
        $this->assertCount(0, $inv->payments);
    }

    public function test_the_unique_index_is_the_last_line_of_defence(): void
    {
        $reception = $this->as('reception');
        $a = $this->createReceivedCase($reception);
        $reception->post('/payments', ['invoiceId' => $a['invoice']['id'], 'amount' => 5, 'method' => 'bank_transfer', 'reference' => 'UNIQ-1'])->assertCreated();
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Payment::create(['invoice_id' => $a['invoice']['id'], 'amount' => 1, 'method' => 'cash', 'reference' => 'UNIQ-1', 'received_by_id' => 'usr_sagal', 'paid_at' => now()]);
    }

    public function test_a_fully_paid_invoice_takes_no_more_money(): void
    {
        $reception = $this->as('reception');
        $c = $this->createReceivedCase($reception);
        $this->assertSame('paid', $reception->post('/payments', ['invoiceId' => $c['invoice']['id'], 'amount' => $c['total'], 'method' => 'cash'])->json('status'));
        $more = $reception->post('/payments', ['invoiceId' => $c['invoice']['id'], 'amount' => 1, 'method' => 'cash']);
        $more->assertStatus(422);
        $this->assertNotEmpty($more->json('errors.amount'));
        $this->assertSame(1, Payment::where('invoice_id', $c['invoice']['id'])->count());
    }

    public function test_accepting_a_submission_with_a_reused_reference_rolls_everything_back(): void
    {
        $reception = $this->as('reception');
        $paid = $this->createReceivedCase($reception);
        $reception->post('/payments', ['invoiceId' => $paid['invoice']['id'], 'amount' => 5, 'method' => 'bank_transfer', 'reference' => 'BANK-77']);
        $sub = $this->as('client')->post('/cases', ['patientId' => 'pat_1024', 'doctorId' => 'doc_amina', 'serviceId' => 'svc_zirconia', 'shade' => 'A2', 'teeth' => [3], 'priority' => 'normal', 'instructions' => '']);
        $historyBefore = CaseStatusHistory::where('case_id', $sub->json('id'))->count();
        $res = $reception->post("/cases/{$sub->json('id')}/status", ['status' => 'received', 'payment' => ['amount' => 5, 'method' => 'bank_transfer', 'reference' => 'BANK-77']]);
        $res->assertStatus(422);
        $this->assertNotEmpty($res->json()['errors']['payment.reference']);
        $row = DentalCase::with('invoice')->find($sub->json('id'));
        $this->assertSame(['submitted', null, null, null], [$row->status, $row->received_at, $row->due_at, $row->invoice]);
        $this->assertSame($historyBefore, CaseStatusHistory::where('case_id', $sub->json('id'))->count());
        $this->assertSame(1, Payment::where('reference', 'BANK-77')->count());
    }
}
