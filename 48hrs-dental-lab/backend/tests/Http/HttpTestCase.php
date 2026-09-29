<?php

namespace Tests\Http;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * Tests against the real thing: Laravel served by `php -S` (several workers, so
 * requests truly run in parallel), and artisan commands as separate processes,
 * all on the MySQL test database with the demo lab anchored to the real clock.
 * These tests commit; afterwards the transactional suites re-migrate.
 */
abstract class HttpTestCase extends BaseTestCase
{
    public const PASSWORD = 'Lab-Test-Pass-2026';

    /** @var array<int, resource> port → server process */
    private static array $servers = [];

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$seeded) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$seeded = true;
        }
        DemoSeeder::$now = null;
        DemoSeeder::$writeFiles = false;
        DemoSeeder::$passwordHash ??= Hash::make(self::PASSWORD);
        (new DemoSeeder)->run();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $proc) {
            proc_terminate($proc);
            proc_close($proc);
        }
        self::$servers = [];
        self::$seeded = false;
        // The next transactional test class rebuilds and re-seeds the database at its fixed clock.
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    /** Environment for child processes: the test database, a shared cache and a real queue. */
    protected static function childEnv(array $extra = []): array
    {
        $keep = ['PATH', 'HOME', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'LAB_TIMEZONE', 'UPLOAD_DIR', 'MAX_UPLOAD_MB', 'SESSION_TTL_MINUTES', 'SESSION_LIFETIME', 'APP_KEY'];
        $env = [];
        foreach ($keep as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $env[$k] = $v;
            }
        }

        return [...$env, 'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'SESSION_DRIVER' => 'database', 'SESSION_SECURE_COOKIE' => 'false',
            'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'info',
            'AUTH_RATE_LIMIT' => '1000', 'API_RATE_LIMIT' => '100000', 'PASSWORD_RESET_RATE_LIMIT' => '1000', ...$extra];
    }

    /** Starts (once per class and port) Laravel under php -S; returns its base URL. */
    protected static function server(int $port, array $env = []): string
    {
        $base = "http://127.0.0.1:{$port}";
        if (! isset(self::$servers[$port])) {
            $root = dirname(__DIR__, 2);
            $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', "{$root}/public", "{$root}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"];
            // Laravel's router script serves from the working directory: public/.
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, "{$root}/public", self::childEnv(['PHP_CLI_SERVER_WORKERS' => '8', ...$env]));
            self::$servers[$port] = $proc;
            for ($i = 0; $i < 100; $i++) {
                $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($fp) {
                    fclose($fp);
                    break;
                }
                usleep(100_000);
            }
        }

        return $base;
    }

    /** Runs an artisan command as its own process. @return array{code: int, out: string} */
    protected static function runArtisan(array $args, array $env = []): array
    {
        $root = dirname(__DIR__, 2);
        $proc = proc_open([PHP_BINARY, 'artisan', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, self::childEnv($env));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

        return ['code' => proc_close($proc), 'out' => $out];
    }

    /** Several artisan commands started together. @return list<array{code: int, out: string}> */
    protected static function runArtisanParallel(array $argsList): array
    {
        $root = dirname(__DIR__, 2);
        $running = [];
        foreach ($argsList as $args) {
            $proc = proc_open([PHP_BINARY, 'artisan', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, self::childEnv());
            fclose($pipes[0]);
            $running[] = [$proc, $pipes];
        }

        return array_map(function ($r) {
            [$proc, $pipes] = $r;
            $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

            return ['code' => proc_close($proc), 'out' => $out];
        }, $running);
    }
}
