<?php

namespace Tests\Http;

use App\Models\DentalCase;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Tests\Support\FailingJob;

/**
 * The scheduler, the deadline scan and the queue as cron runs them on the
 * server: separate `php artisan` processes on the real clock.
 */
class SchedulerQueueTest extends HttpTestCase
{
    private function makeOverdue(): DentalCase
    {
        DentalCase::query()->update(['at_risk_notified_at' => now(), 'overdue_notified_at' => now()]);
        $c = DentalCase::where('status', 'in_production')->first();
        $c->forceFill(['received_at' => '2001-01-01 08:00:00', 'due_at' => '2001-01-03 08:00:00', 'at_risk_notified_at' => null, 'overdue_notified_at' => null])->save();

        return $c;
    }

    public function test_the_scan_command_with_nothing_to_do_changes_nothing(): void
    {
        DentalCase::query()->update(['at_risk_notified_at' => now(), 'overdue_notified_at' => now()]);
        $before = UserNotification::count();
        $run = self::runArtisan(['lab:scan-deadlines']);
        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertStringContainsString('Checked 0 case(s)', $run['out']);
        $this->assertSame($before, UserNotification::count());
    }

    public function test_pending_alerts_are_sent_once_and_a_second_run_does_not_repeat_them(): void
    {
        $c = $this->makeOverdue();
        $run = self::runArtisan(['lab:scan-deadlines']);
        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertStringContainsString('1 overdue', $run['out']);
        $this->assertNotNull($c->fresh()->overdue_notified_at);
        $sent = UserNotification::where('case_id', $c->id)->where('type', 'case_overdue')->count();
        $this->assertGreaterThan(0, $sent);
        $this->assertSame(0, self::runArtisan(['lab:scan-deadlines'])['code']);
        $this->assertSame($sent, UserNotification::where('case_id', $c->id)->where('type', 'case_overdue')->count());
    }

    public function test_scans_started_together_send_each_alert_exactly_once(): void
    {
        $c = $this->makeOverdue();
        $runs = self::runArtisanParallel([['lab:scan-deadlines'], ['lab:scan-deadlines'], ['lab:scan-deadlines']]);
        $this->assertSame([0, 0, 0], array_column($runs, 'code'), implode("\n", array_column($runs, 'out')));
        $users = UserNotification::where('case_id', $c->id)->where('type', 'case_overdue')->pluck('user_id')->all();
        $this->assertNotEmpty($users);
        $this->assertSame(count($users), count(array_unique($users))); // nobody notified twice
    }

    public function test_schedule_run_dispatches_the_scan_and_drains_the_queue(): void
    {
        $c = $this->makeOverdue();
        DB::table('jobs')->delete();
        DB::table('cache_locks')->delete();
        $run = self::runArtisan(['schedule:run']);
        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertStringContainsString('scan-deadlines', $run['out']);
        $this->assertStringContainsString('queue:work', $run['out']);
        $this->assertSame(0, DB::table('jobs')->count(), 'the queue was drained');
        $this->assertGreaterThan(0, UserNotification::where('case_id', $c->id)->where('type', 'case_overdue')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_password_reset_mail_goes_through_the_queue(): void
    {
        DB::table('jobs')->delete();
        $base = self::server(8393);
        $c = new HttpClient($base);
        $c->get('/api/auth/csrf');
        $this->assertSame(200, $c->post('/api/auth/forgot-password', ['email' => 'omar@48hrs.lab'])['status']);
        $this->assertSame(1, DB::table('jobs')->count(), 'the reset e-mail is queued, not sent during the request');
        $run = self::runArtisan(['queue:work', '--stop-when-empty', '--tries=3']);
        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_job_that_keeps_failing_ends_up_in_failed_jobs(): void
    {
        DB::table('failed_jobs')->delete();
        dispatch(new FailingJob)->onConnection('database');
        $run = self::runArtisan(['queue:work', '--stop-when-empty', '--tries=2', '--backoff=0']);
        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertSame(0, DB::table('jobs')->count());
        $failed = DB::table('failed_jobs')->get();
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('FailingJob', $failed[0]->payload);
        $this->assertStringContainsString('deliberate failure', $failed[0]->exception);
        $list = self::runArtisan(['queue:failed']);
        $this->assertStringContainsString('FailingJob', $list['out']);
    }

    public function test_the_scan_fails_loudly_when_the_database_is_unreachable(): void
    {
        $run = self::runArtisan(['lab:scan-deadlines'], ['DB_DATABASE' => 'no_such_database_48hrs']);
        $this->assertNotSame(0, $run['code']);
    }
}
