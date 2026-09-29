<?php

namespace Tests\Feature;

use App\Models\DentalCase;
use App\Models\Invoice;
use App\Models\Payment;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    public function test_unpaid_partial_paid_with_the_balance_computed_by_the_server(): void
    {
        $reception = $this->as('reception');
        $c = $this->createReceivedCase($reception); // 2 × $20 = $40
        $invoiceId = $c['invoice']['id'];
        $inv = $reception->get("/invoices/{$invoiceId}");
        $inv->assertJson(['total' => 40, 'paid' => 0, 'remaining' => 40, 'status' => 'unpaid', 'lineItems' => [['quantity' => 2, 'unitPrice' => 20, 'amount' => 40]]]);

        $reception->post('/payments', ['invoiceId' => $invoiceId, 'amount' => 15, 'method' => 'cash'])->assertCreated()->assertJson(['paid' => 15, 'remaining' => 25, 'status' => 'partial']);
        $this->advanceMinutes(1);
        $inv = $reception->post('/payments', ['invoiceId' => $invoiceId, 'amount' => 25, 'method' => 'mobile_money', 'reference' => 'MM-7']);
        $inv->assertCreated()->assertJson(['paid' => 40, 'remaining' => 0, 'status' => 'paid']);
        $this->assertCount(2, $inv->json('payments'));
        $inv->assertJson(['payments' => [['amount' => 25, 'method' => 'mobile_money', 'reference' => 'MM-7', 'receivedByName' => 'Sagal Warsame']]]);

        $row = Invoice::with('payments')->find($invoiceId);
        $this->assertEquals($row->payments->sum('amount'), $row->amount_paid);
        $this->assertSame('paid', $reception->get("/cases/{$c['id']}")->json('paymentStatus'));
    }

    public function test_decimal_amounts_are_exact(): void
    {
        $reception = $this->as('reception');
        $c = $this->createReceivedCase($reception, ['serviceId' => 'svc_zirconia', 'teeth' => [3]]); // $20
        foreach ([0.1, 0.2, 19.7] as $amount) {
            $reception->post('/payments', ['invoiceId' => $c['invoice']['id'], 'amount' => $amount, 'method' => 'cash'])->assertCreated();
        }
        $reception->get("/invoices/{$c['invoice']['id']}")->assertJson(['paid' => 20, 'remaining' => 0, 'status' => 'paid']);
    }

    public function test_overdue_once_the_due_date_passes_with_money_owed(): void
    {
        $c = $this->createReceivedCase($this->as('reception'));
        $this->advanceMinutes(15 * 24 * 60);
        $later = $this->as('reception');
        $this->assertSame('overdue', $later->get("/invoices/{$c['invoice']['id']}")->json('status'));
        $overdue = $later->get('/invoices?status=overdue&perPage=200')->json('data');
        $this->assertContains($c['invoice']['id'], array_column($overdue, 'id'));
        $this->assertTrue(collect($overdue)->every(fn ($i) => $i['status'] === 'overdue'));
    }

    public function test_invalid_payments_are_422_and_leave_the_balance_unchanged(): void
    {
        $reception = $this->as('reception');
        $c = $this->createReceivedCase($reception);
        $invoiceId = $c['invoice']['id'];
        $cases = [
            [['invoiceId' => $invoiceId, 'amount' => 0, 'method' => 'cash'], 'amount'],
            [['invoiceId' => $invoiceId, 'amount' => -5, 'method' => 'cash'], 'amount'],
            [['invoiceId' => $invoiceId, 'amount' => 41, 'method' => 'cash'], 'amount'],
            [['invoiceId' => $invoiceId, 'amount' => 'ten', 'method' => 'cash'], 'amount'],
            [['invoiceId' => $invoiceId, 'amount' => 10, 'method' => 'bank_transfer'], 'reference'],
            [['invoiceId' => $invoiceId, 'amount' => 10, 'method' => 'bitcoin'], 'method'],
            [['invoiceId' => $invoiceId, 'amount' => 10, 'method' => 'cash', 'paidAt' => gmdate('Y-m-d\TH:i:s\Z', (int) (self::NOW / 1000) + 2 * 86400)], 'paidAt'],
            [['amount' => 10, 'method' => 'cash'], 'invoiceId'],
        ];
        foreach ($cases as [$body, $field]) {
            $res = $reception->post('/payments', $body);
            $this->assertSame(422, $res->status(), json_encode($body));
            $this->assertArrayHasKey($field, $res->json('errors'), json_encode($body).' '.$res->getContent());
        }
        $reception->post('/payments', ['invoiceId' => 'inv_missing', 'amount' => 1, 'method' => 'cash'])->assertStatus(404);
        $this->assertEquals(0, Invoice::find($invoiceId)->amount_paid);
        $this->assertSame(0, Payment::where('invoice_id', $invoiceId)->count());
    }

    public function test_a_reference_is_recorded_once_across_all_invoices(): void
    {
        $reception = $this->as('reception');
        $a = $this->createReceivedCase($reception);
        $b = $this->createReceivedCase($reception);
        $reception->post('/payments', ['invoiceId' => $a['invoice']['id'], 'amount' => 5, 'method' => 'bank_transfer', 'reference' => 'TRX-100'])->assertCreated();
        foreach (['TRX-100', ' TRX-100 '] as $ref) { // compared exactly, after trimming (as in the Node API)
            $dup = $reception->post('/payments', ['invoiceId' => $b['invoice']['id'], 'amount' => 5, 'method' => 'bank_transfer', 'reference' => $ref]);
            $dup->assertStatus(422);
            $this->assertMatchesRegularExpression('/already recorded/', $dup->json('errors.reference.0'));
        }
        // Accepting a case with a deposit goes through the same check.
        $sub = $this->as('client')->post('/cases', ['patientId' => 'pat_1024', 'doctorId' => 'doc_amina', 'serviceId' => 'svc_zirconia', 'shade' => 'A2', 'teeth' => [3], 'priority' => 'normal', 'instructions' => '']);
        $res = $reception->post("/cases/{$sub->json('id')}/status", ['status' => 'received', 'payment' => ['amount' => 5, 'method' => 'bank_transfer', 'reference' => 'TRX-100']]);
        $res->assertStatus(422);
        $this->assertArrayHasKey('payment.reference', $res->json('errors'));
        $this->assertSame('submitted', DentalCase::find($sub->json('id'))->status);
        $this->assertSame(1, Payment::where('reference', 'TRX-100')->count());
    }

    public function test_only_payments_record_may_take_money_and_clients_see_only_their_clinic(): void
    {
        $tech = $this->as('technician');
        $inv = Invoice::where('clinic_id', 'cln_smile')->first();
        $tech->post('/payments', ['invoiceId' => $inv->id, 'amount' => 1, 'method' => 'cash'])->assertStatus(403);
        $tech->get('/invoices')->assertStatus(403);
        $client = $this->as('client');
        $mine = $client->get('/invoices?perPage=200')->json('data');
        $this->assertCount(Invoice::where('clinic_id', 'cln_smile')->count(), $mine);
        $this->assertTrue(collect($mine)->every(fn ($i) => $i['clinic']['id'] === 'cln_smile'));
        $other = Invoice::where('clinic_id', '!=', 'cln_smile')->first();
        $client->get("/invoices/{$other->id}")->assertStatus(404);
        $client->get('/payments')->assertStatus(403);
    }

    public function test_invoices_are_listed_filtered_searched_and_sorted_by_balance(): void
    {
        $admin = $this->as('admin');
        $remaining = array_column($admin->get('/invoices?sort=remaining&dir=desc&perPage=200')->json('data'), 'remaining');
        $sorted = $remaining;
        rsort($sorted);
        $this->assertSame($sorted, $remaining);
        $paid = $admin->get('/invoices?status=paid&perPage=200')->json('data');
        $this->assertNotEmpty($paid);
        $this->assertTrue(collect($paid)->every(fn ($i) => $i['status'] === 'paid'));
        $one = Invoice::first();
        $this->assertSame([$one->id], array_column($admin->get("/invoices?search={$one->invoice_number}")->json('data'), 'id'));
        $this->assertSame($one->id, $admin->get("/invoices/{$one->invoice_number}")->json('id'));
        $payments = $admin->get('/payments?method=cash&perPage=200')->json('data');
        $this->assertTrue(collect($payments)->every(fn ($p) => $p['method'] === 'cash'));
        $this->assertArrayHasKey('caseNumber', $payments[0]);
    }

    public function test_post_invoices_issues_a_missing_invoice_once(): void
    {
        $reception = $this->as('reception');
        $c = $this->createReceivedCase($reception);
        Invoice::where('case_id', $c['id'])->delete();
        $reception->post('/invoices', ['caseId' => $c['id']])->assertCreated()->assertJson(['caseId' => $c['id'], 'total' => 40, 'status' => 'unpaid']);
        $reception->post('/invoices', ['caseId' => $c['id']])->assertStatus(422);
        $submitted = DentalCase::where('status', 'submitted')->first();
        $this->assertMatchesRegularExpression('/received/', $reception->post('/invoices', ['caseId' => $submitted->id])->json('errors.caseId.0'));
    }
}
