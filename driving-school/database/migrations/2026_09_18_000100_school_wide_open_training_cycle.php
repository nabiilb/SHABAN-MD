<?php

use App\Support\LegacySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One open training cycle per student — across the whole school, not per day.
 *
 * The guard added when repeat training became possible was
 * (active_student_id, queue_date): one OPEN entry per student per DAY. That was
 * right while each teacher queued only their own students, because an entry
 * left open overnight belonged to somebody who would see it in the morning.
 *
 * It is wrong now that any instructor may train any student. Instructor A
 * queues Ahmed and never trains him; tomorrow Instructor B searches Ahmed,
 * finds nothing blocking, and opens a second cycle — two teachers each holding
 * the same student, and the day boundary is the only reason the database
 * allowed it. So the guard drops queue_date and becomes school-wide.
 *
 * Also adds the three columns a closed cycle needs in order to say what
 * happened to it. Until now a cancelled entry could only be explained by
 * overwriting `notes`, which is the instructor's own field:
 *
 *   closed_at      when the cycle was closed
 *   closed_by      who closed it (null when the system expired it)
 *   close_reason   "Removed from queue by instructor", "Auto-expired after 12 hours"
 *
 * THE ONE PLACE THIS MIGRATION WRITES TO EXISTING ROWS: a student holding more
 * than one open cycle cannot exist under the new index, and older databases
 * have them — the per-day guard allowed one per day, and the auto-enrolment
 * this application used to do left plenty behind. The NEWEST open cycle per
 * student is kept; every older one is closed as `cancelled` with a reason
 * saying so. Nothing is deleted, every row stays queryable as history, and the
 * count is printed so it can be checked. No session, attendance, evaluation or
 * payment is touched.
 */
return new class extends Migration
{
    protected const TABLE = 'training_queue_entries';

    protected const OLD_INDEX = 'training_queue_entries_active_student_unique';

    protected const NEW_INDEX = 'training_queue_entries_open_cycle_unique';

    protected const OPEN = "'waiting', 'training_in_progress', 'attendance_pending'";

    public function up(): void
    {
        if (! LegacySchema::hasTable(self::TABLE)) {
            return;
        }

        $this->addClosureColumns();
        $this->closeOlderOpenCycles();

        // The old composite index goes only once nothing can violate the new
        // one, so a failure part-way through leaves a table that still guards
        // itself.
        if (! LegacySchema::hasIndex(self::TABLE, self::NEW_INDEX)) {
            DB::statement('ALTER TABLE '.self::TABLE.' ADD UNIQUE KEY '.self::NEW_INDEX.' (active_student_id)');
        }

        if (LegacySchema::hasIndex(self::TABLE, self::OLD_INDEX)) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP INDEX '.self::OLD_INDEX);
        }
    }

    public function down(): void
    {
        if (! LegacySchema::hasTable(self::TABLE)) {
            return;
        }

        if (! LegacySchema::hasIndex(self::TABLE, self::OLD_INDEX)) {
            DB::statement('ALTER TABLE '.self::TABLE.'
                ADD UNIQUE KEY '.self::OLD_INDEX.' (active_student_id, queue_date)');
        }

        if (LegacySchema::hasIndex(self::TABLE, self::NEW_INDEX)) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP INDEX '.self::NEW_INDEX);
        }

        // The closure columns are left in place: they hold the record of why
        // cycles were closed, and dropping them would destroy that.
    }

    protected function addClosureColumns(): void
    {
        $columns = [
            'closed_at' => 'DATETIME NULL',
            'closed_by' => 'BIGINT UNSIGNED NULL',
            'close_reason' => 'VARCHAR(255) NULL',
        ];

        foreach ($columns as $column => $definition) {
            if (! LegacySchema::hasColumn(self::TABLE, $column)) {
                DB::statement('ALTER TABLE '.self::TABLE." ADD COLUMN {$column} {$definition}");
            }
        }
    }

    /**
     * Keeps the newest open cycle per student and closes the rest.
     *
     * Ordered by queue_date then id, so "newest" means the one a teacher would
     * actually be looking at. The guard column is cleared by the same write, so
     * the unique index can go on afterwards.
     */
    protected function closeOlderOpenCycles(): void
    {
        $clashes = DB::select('
            SELECT active_student_id AS student_id, COUNT(*) AS cycles
            FROM '.self::TABLE.'
            WHERE active_student_id IS NOT NULL
            GROUP BY active_student_id
            HAVING COUNT(*) > 1
        ');

        if ($clashes === []) {
            return;
        }

        $closed = 0;

        foreach ($clashes as $clash) {
            $keep = DB::table(self::TABLE)
                ->where('active_student_id', $clash->student_id)
                ->orderByDesc('queue_date')
                ->orderByDesc('id')
                ->value('id');

            $closed += DB::table(self::TABLE)
                ->where('active_student_id', $clash->student_id)
                ->where('id', '!=', $keep)
                ->update([
                    'status' => 'cancelled',
                    'active_student_id' => null,
                    'closed_at' => now(),
                    'close_reason' => 'Closed when one open cycle per student became school-wide',
                    'updated_at' => now(),
                ]);
        }

        echo PHP_EOL."  Closed {$closed} older open queue cycle(s) across ".count($clashes)
            .' student(s), so that one student holds at most one open cycle.'.PHP_EOL
            .'  Nothing was deleted — every row is still in the queue history with a reason.'.PHP_EOL.PHP_EOL;
    }
};
