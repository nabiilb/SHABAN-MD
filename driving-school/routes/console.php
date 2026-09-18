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
| One cron entry drives all of it:
|
|     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Closes off yesterday's register: every active student with no record for
// it is marked absent. Half past midnight, in the school's own timezone, so
// the day is genuinely over before anything is written.
Schedule::command('attendance:mark-absent')
    ->dailyAt('00:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// Closes training cycles nobody finished. Hourly, because a cycle left open
// at 20:00 reaches its twelve hours in the middle of the night.
Schedule::command('training:expire-stale')
    ->hourly()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
