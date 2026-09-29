<?php

namespace Tests;

use App\Domain\Dates;
use App\Support\CaseFiles;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ApiClient;

/**
 * Feature tests run against MySQL (dental_lab_test): migrated and loaded with the
 * demo lab once per run, then every test runs in a rolled-back transaction.
 * The clock is fixed at NOW (a Monday morning in the lab's time zone), the
 * instant the demo data is anchored to.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Test-only password of every demo account. */
    public const PASSWORD = 'Lab-Test-Pass-2026';

    /** 2026-06-15T09:00:00Z */
    public const NOW = 1781514000000;

    public const USERS = [
        'superAdmin' => 'khalid@48hrs.lab',
        'admin' => 'hodan@48hrs.lab',
        'manager' => 'omar@48hrs.lab',
        'reception' => 'sagal@48hrs.lab',
        'technician' => 'fatima@48hrs.lab',
        'technician2' => 'ahmed@48hrs.lab',
        'qc' => 'idil@48hrs.lab',
        'delivery' => 'bashir@48hrs.lab',
        'client' => 'amina@smiledental.so',
        'disabledClient' => 'layla@horizondental.so',
    ];

    /** Cookies are passed back exactly as the API set them (already encrypted). */
    protected $encryptCookies = false;

    protected bool $seed = true;

    protected string $seeder = DemoSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToMs(self::NOW);
    }

    protected function beforeRefreshingDatabase()
    {
        $this->travelToMs(self::NOW);
        DemoSeeder::$now = self::NOW;
        DemoSeeder::$writeFiles = false;
        DemoSeeder::$passwordHash = Hash::make(self::PASSWORD);
    }

    public function travelToMs(int $ms): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampMs($ms, 'UTC'));
    }

    /** Moves the clock by whole minutes (Carbon, so sessions, SLA and notifications agree). */
    public function advanceMinutes(int $minutes): void
    {
        $this->travelToMs(Dates::nowMs() + $minutes * 60_000);
    }

    /** A browser-like client that keeps its own cookies (its own session). */
    public function client(): ApiClient
    {
        return new ApiClient($this);
    }

    public function as(string $who, ?string $password = null): ApiClient
    {
        $c = $this->client();
        $res = $c->post('/auth/login', ['email' => self::USERS[$who] ?? $who, 'password' => $password ?? self::PASSWORD]);
        $this->assertSame(200, $res->status(), "login {$who}: ".$res->getContent());

        return $c;
    }

    /** Creates a case at reception (clock starts now) for the Smile clinic. */
    public function createReceivedCase(ApiClient $c, array $overrides = []): array
    {
        $res = $c->post('/cases', [
            'patientId' => 'pat_1024', 'doctorId' => 'doc_amina', 'clinicId' => 'cln_smile', 'serviceId' => 'svc_zirconia',
            'shade' => 'A2', 'teeth' => [8, 9], 'priority' => 'normal', 'instructions' => 'Test case', 'receiveNow' => true, ...$overrides,
        ]);
        $this->assertSame(201, $res->status(), $res->getContent());

        return $res->json();
    }

    /** Lab-calendar day (LAB_TIMEZONE) of an ISO instant. */
    public static function labDay(string|int $at): string
    {
        $ms = is_int($at) ? $at : Dates::ms($at);

        return Dates::dayIn($ms, config('lab.timezone'));
    }

    /** Called by ApiClient (the request helpers are protected on the test case). */
    public function send(string $method, string $uri, array $params, array $cookies, array $files, array $headers, ?string $content)
    {
        return $this->call($method, $uri, $params, $cookies, $files, $this->transformHeadersToServerVars($headers), $content);
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        CaseFiles::clear();
        parent::tearDown();
    }
}
