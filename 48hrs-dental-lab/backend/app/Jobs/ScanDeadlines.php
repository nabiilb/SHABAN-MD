<?php

namespace App\Jobs;

use App\Services\DeadlineScanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Raises at-risk and overdue alerts (replaces the Node deadline worker).
 * Dispatched every minute by the scheduler; unique, so a backlog never piles
 * up duplicate scans, and each alert is claimed once per case anyway.
 */
class ScanDeadlines implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** Seconds the uniqueness lock is held if a job never finishes. */
    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function handle(DeadlineScanner $scanner): void
    {
        $scan = $scanner->run();
        if ($scan['atRisk'] || $scan['overdue']) {
            Log::info('deadline alerts raised', $scan);
        }
    }
}
