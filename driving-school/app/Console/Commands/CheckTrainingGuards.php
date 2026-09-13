<?php

namespace App\Console\Commands;

use App\Models\TrainingSession;
use App\Support\LegacySchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports whether the two concurrency guards are in place and consistent.
 *
 * Useful straight after `php artisan migrate` on a server whose version is in
 * doubt: it prints what the server actually is, whether both guard columns are
 * plain columns under unique indexes, and whether any row disagrees with its
 * own status.
 */
class CheckTrainingGuards extends Command
{
    protected $signature = 'training:check-guards';

    protected $description = 'Verify the training-session concurrency guards against the live database';

    public function handle(): int
    {
        $this->line('Server:   '.DB::selectOne('select version() as v')->v);
        $this->line('Driver:   '.DB::connection()->getDriverName());

        if (! LegacySchema::hasTable('training_sessions')) {
            $this->error('training_sessions does not exist — run php artisan migrate first.');

            return self::FAILURE;
        }

        $ok = true;

        foreach ([
            'active_student_id' => ['training_sessions_active_student_unique', 'one live session per student'],
            'active_instructor_id' => ['training_sessions_active_instructor_unique', 'one live session per teacher'],
        ] as $guard => [$index, $rule]) {
            $hasColumn = LegacySchema::hasColumn('training_sessions', $guard);
            $hasIndex = LegacySchema::hasIndex('training_sessions', $index);
            $generated = LegacySchema::isGeneratedColumn('training_sessions', $guard);

            $ok = $ok && $hasColumn && $hasIndex && ! $generated;

            $this->line(sprintf(
                '%s %-22s column:%s index:%s%s  (%s)',
                $hasColumn && $hasIndex && ! $generated ? '  OK  ' : ' FAIL ',
                $guard,
                $hasColumn ? 'yes' : 'NO',
                $hasIndex ? 'yes' : 'NO',
                $generated ? ' GENERATED (needs MySQL 5.7; re-run migrate)' : '',
                $rule,
            ));
        }

        $drifted = TrainingSession::query()
            ->where(function ($query) {
                $query->whereIn('status', TrainingSession::LIVE_STATUSES)
                    ->where(fn ($q) => $q->whereColumn('active_student_id', '!=', 'student_id')
                        ->orWhereNull('active_student_id')
                        ->orWhereColumn('active_instructor_id', '!=', 'instructor_id')
                        ->orWhereNull('active_instructor_id'));
            })
            ->orWhere(function ($query) {
                $query->whereNotIn('status', TrainingSession::LIVE_STATUSES)
                    ->where(fn ($q) => $q->whereNotNull('active_student_id')->orWhereNotNull('active_instructor_id'));
            })
            ->pluck('id');

        if ($drifted->isNotEmpty()) {
            $this->error('Rows whose guard columns disagree with their status: '.$drifted->implode(', '));

            return self::FAILURE;
        }

        $this->info('  OK   every session\'s guard columns match its status ('.TrainingSession::count().' rows).');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
