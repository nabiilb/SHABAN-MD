<?php

use App\Jobs\ScanDeadlines;
use App\Services\DeadlineScanner;
use App\Support\Housekeeping;
use App\Support\ProductionConfig;
use App\Support\RouteManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('lab:check-config', function () {
    $problems = ProductionConfig::problems(ProductionConfig::current());
    if (! $problems) {
        $this->info('Configuration OK for '.config('app.env').'.');

        return 0;
    }
    foreach ($problems as $p) {
        $this->error($p);
    }

    return 1;
})->purpose('Check the deployment configuration (APP_DEBUG, https URLs, secure cookies, SMTP, placeholders)');

Artisan::command('lab:route-manifest', function () {
    file_put_contents(base_path('routes/api-manifest.json'), RouteManifest::build());
    $this->info('Wrote routes/api-manifest.json');
})->purpose('Write routes/api-manifest.json (the route list the web app contract test checks)');

Artisan::command('lab:scan-deadlines', function (DeadlineScanner $scanner) {
    $scan = $scanner->run();
    $this->info(sprintf('Checked %d case(s): %d at risk, %d overdue, %d notification(s).', $scan['checked'], $scan['atRisk'], $scan['overdue'], $scan['notifications']));

    return 0;
})->purpose('Raise at-risk and overdue alerts now, without the queue (each alert is sent once per case)');

Artisan::command('lab:purge-expired', function () {
    $purged = Housekeeping::purgeExpiredSessions();
    $this->info("Removed {$purged['sessions']} expired session(s).");

    return 0;
})->purpose('Remove sessions that ended more than a week ago');

/*
| The schedule. On Hostinger one cron entry runs it every minute:
|   * * * * * cd ~/domains/<domain>/laravel && php artisan schedule:run >> /dev/null 2>&1
| Every task takes a cache lock (withoutOverlapping) so a slow run never doubles up.
*/
Schedule::job(new ScanDeadlines)->everyMinute()->name('scan-deadlines');

// The queue worker for this host (no supervisor on shared hosting): drain the queue, then exit.
Schedule::command('queue:work', ['--stop-when-empty', '--max-time=50', '--tries=3', '--sleep=1'])
    ->everyMinute()->withoutOverlapping(10)->name('queue-worker');

Schedule::command('lab:purge-expired')->hourly()->withoutOverlapping();
Schedule::command('auth:clear-resets')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed', ['--hours=720'])->daily()->withoutOverlapping();
