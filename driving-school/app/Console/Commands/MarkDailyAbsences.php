<?php

namespace App\Console\Commands;

use App\Services\DailyAbsenceService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Closes off a finished day's register by marking the students who did not
 * attend it.
 *
 * Run without arguments it does yesterday, in the school's own timezone, which
 * is what the scheduler calls shortly after midnight. A date may be given for
 * a day that was missed — but only ever explicitly: a normal run never reaches
 * back over history, because silently filling in months of absences would
 * rewrite what the school knows about its own students.
 *
 *     php artisan attendance:mark-absent
 *     php artisan attendance:mark-absent --date=2026-09-18
 *     php artisan attendance:mark-absent --date=2026-09-18 --dry-run
 *
 * Safe to run repeatedly: a student who already has any record for the day is
 * left exactly as they are.
 */
class MarkDailyAbsences extends Command
{
    protected $signature = 'attendance:mark-absent
                            {--date= : The finished day to close off (defaults to yesterday)}
                            {--dry-run : Report what would be written without writing it}';

    protected $description = 'Mark active students absent for a finished day they have no attendance for';

    public function handle(DailyAbsenceService $absences): int
    {
        $date = $this->option('date') ?: $absences->defaultDate()->toDateString();

        try {
            if ($this->option('dry-run')) {
                return $this->reportOnly($absences, $date);
            }

            $result = $absences->markAbsent($date);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($result['created'] === 0
            ? "Every active student already has a record for {$result['date']} — nothing to do."
            : "Marked {$result['created']} student(s) absent for {$result['date']}.");

        $this->line("{$result['students']} active student(s) considered; {$result['skipped']} already had a record.");

        return self::SUCCESS;
    }

    /** Counts what a real run would write, without writing any of it. */
    protected function reportOnly(DailyAbsenceService $absences, string $date): int
    {
        $result = $absences->preview($date);

        $this->info("{$result['would_create']} student(s) would be marked absent for {$result['date']}.");
        $this->line("{$result['students']} active student(s) considered. Nothing was written — this was a dry run.");

        return self::SUCCESS;
    }
}
