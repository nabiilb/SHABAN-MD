<?php

namespace App\Console\Commands;

use App\Models\Instructor;
use App\Models\TrainingQueueEntry;
use App\Models\User;
use App\Services\TrainingBoardService;
use App\Services\TrainingQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Says what each dashboard will show for a date, and why.
 *
 * When a teacher's console looks empty, this settles in one command whether the
 * server has nothing to give it or the browser is not rendering what it was
 * given: it prints the admin's count, every teacher's line as the board service
 * builds it, and any waiting entry that no console can reach.
 */
class CheckTrainingQueue extends Command
{
    protected $signature = 'training:check-queue {--date= : The day to inspect, defaults to today}';

    protected $description = 'Show the waiting queue as the admin board and each teacher console see it';

    public function handle(TrainingQueueService $queue, TrainingBoardService $boards): int
    {
        $date = Carbon::parse($this->option('date') ?? today())->toDateString();

        $this->line('Date:   '.$date);

        $waiting = TrainingQueueEntry::forDate($date)->waiting()->with('student.currentInstructor')->ordered()->get();

        $this->line('Admin "Currently Waiting": '.$waiting->count());
        $this->newLine();

        $instructors = Instructor::active()->orderBy('full_name')->get();

        if ($instructors->isEmpty()) {
            $this->error('No active instructors — every queue will be empty.');
        }

        $reachable = [];

        foreach ($instructors as $instructor) {
            $line = $queue->waitingFor($instructor->id, $date);
            $reachable = array_merge($reachable, $line->pluck('id')->all());

            $this->line(sprintf('%-24s %d waiting', $instructor->full_name, $line->count()));

            foreach ($line as $index => $entry) {
                $this->line(sprintf(
                    '    #%d %-24s %s',
                    $index + 1,
                    $entry->student?->full_name,
                    strtoupper($entry->ownershipOn()),
                ));
            }

            // What the console actually renders, through the board service and
            // the same JSON the browser polls.
            if ($user = User::where('id', $instructor->user_id)->first()) {
                $board = $boards->board($user, $date);
                $this->line(sprintf(
                    '    board endpoint: queue_total=%s, rows=%d, current=%s',
                    $board['queue_total'] ?? 'MISSING',
                    count($board['queue'] ?? []),
                    $board['current']['student'] ?? '—',
                ));
            } else {
                $this->warn('    no login is linked to this instructor — they cannot open the console.');
            }
        }

        $this->newLine();

        $orphans = $waiting->reject(fn (TrainingQueueEntry $entry) => in_array($entry->id, $reachable, true));

        if ($orphans->isEmpty()) {
            $this->info('Every waiting student appears on at least one teacher console.');

            return self::SUCCESS;
        }

        $this->error('Waiting students no console can reach:');

        foreach ($orphans as $entry) {
            $owner = $entry->student?->instructorIdOn($date);

            $this->line(sprintf(
                '  %-24s permanent=%s  owner-for-date=%s  preferred=%s',
                $entry->student?->full_name,
                $entry->student?->currentInstructor?->full_name ?? 'none',
                $owner ? (Instructor::find($owner)?->full_name.' ('.Instructor::find($owner)?->status.')') : 'none',
                $entry->preferredInstructor?->full_name ?? 'any',
            ));
        }

        return self::FAILURE;
    }
}
