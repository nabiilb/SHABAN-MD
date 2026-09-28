<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Production is shared hosting, where the scheduler may or may not be wired
| up to cron. Every command below is written to be run by hand as well, and
| to be safe to run twice, so a missed night costs nothing.
|
| Nothing here writes attendance. A day a student did not attend is counted
| by NoAttendanceService when somebody looks at it — see "No Attendance" in
| the README — rather than being written into the register overnight.
|
| One cron entry drives all of it:
|
|     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Closes training cycles nobody finished. Hourly, because a cycle left open
// at 20:00 reaches its twelve hours in the middle of the night.
Schedule::command('training:expire-stale')
    ->hourly()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
