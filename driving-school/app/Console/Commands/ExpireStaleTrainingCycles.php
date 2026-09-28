<?php

namespace App\Console\Commands;

use App\Services\TrainingStaleCycleService;
use Illuminate\Console\Command;

/**
 * Closes training cycles nobody finished.
 *
 * The application also does this at request time, before any Training Console
 * decision, so a stale entry never blocks a student just because cron did not
 * run. This command is for the hours when nobody is looking at a console —
 * overnight, mostly, which is exactly when a cycle left open at 20:00 reaches
 * its twelve hours.
 *
 * Idempotent: run it as often as you like. A cycle that is not stale is left
 * alone, one already closed is not closed again, and nothing here ever adds a
 * student to a queue, starts a session, or writes attendance.
 *
 * Hourly is the right cadence, once somebody sets it up:
 *
 *     0 * * * * cd /path/to/app && php artisan training:expire-stale
 */
class ExpireStaleTrainingCycles extends Command
{
    protected $signature = 'training:expire-stale
                            {--dry-run : Report what would be closed without closing anything}';

    protected $description = 'Close training queue cycles left unfinished for more than the stale limit';

    public function handle(TrainingStaleCycleService $stale): int
    {
        $hours = $stale->staleHours();

        if ($this->option('dry-run')) {
            $count = $stale->staleCount();

            $this->info("{$count} open training cycle(s) have been unresolved for {$hours} hours or more.");
            $this->line('Nothing was closed — this was a dry run.');

            return self::SUCCESS;
        }

        $closed = $stale->expireStale();

        $this->info($closed === 0
            ? "No training cycles have been open longer than {$hours} hours."
            : "Closed {$closed} training cycle(s) left unfinished for {$hours} hours or more.");

        if ($closed > 0) {
            $this->line('Every one is still in the queue history with its reason. No attendance,');
            $this->line('evaluation or lesson was created, and no student was put in cooldown.');
        }

        return self::SUCCESS;
    }
}
