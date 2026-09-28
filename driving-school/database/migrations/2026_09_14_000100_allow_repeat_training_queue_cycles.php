<?php

use App\Support\LegacySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A student may train more than once on the same calendar day.
 *
 * The cooldown between sessions is twelve rolling hours, so 07:00 and 19:00 on
 * the same date are both legitimate — but the table was created with
 *
 *     UNIQUE (student_id, queue_date)
 *
 * which allows a student exactly one row per day. The old code lived with that
 * by *reusing* the finished row: it set a completed cycle back to `waiting` and
 * overwrote joined_at. That is history being rewritten in place — the morning
 * cycle stopped existing the moment the evening one began.
 *
 * So uniqueness moves off "one row per day" and onto "one OPEN row per day":
 *
 *   - active_student_id is a guard column, not data. TrainingQueueEntry keeps
 *     it equal to student_id while the entry is waiting / training / awaiting
 *     evaluation, and NULL once it is completed or cancelled.
 *   - UNIQUE (active_student_id, queue_date) therefore still refuses two
 *     simultaneous open entries for one student on one date — NULLs do not
 *     collide in a MySQL unique index, so any number of *finished* cycles may
 *     sit behind them.
 *
 * The concurrency guarantee is unchanged: two teachers pressing Add at the same
 * instant still produce exactly one waiting row, enforced by the database and
 * not merely by the lock in front of it.
 *
 * Additive and idempotent. Nothing is deleted, no existing row is altered
 * except to compute the new guard column from the status it already has, and
 * the pairs the old index forbade cannot exist in the data, so the new index
 * can never find a duplicate on an existing database. Plain columns and plain
 * indexes only — nothing here is newer than MySQL 5.5.
 */
return new class extends Migration
{
    protected const TABLE = 'training_queue_entries';

    protected const OLD_UNIQUE = 'training_queue_entries_student_id_queue_date_unique';

    protected const LOOKUP_INDEX = 'training_queue_entries_student_id_queue_date_index';

    protected const GUARD_UNIQUE = 'training_queue_entries_active_student_unique';

    protected const OPEN = "'waiting', 'training_in_progress', 'attendance_pending'";

    public function up(): void
    {
        if (! LegacySchema::hasTable(self::TABLE)) {
            return;
        }

        // The pair is still worth an index — every queue read filters on it.
        // Added before the unique one goes, so the lookups are never unindexed.
        if (! LegacySchema::hasIndex(self::TABLE, self::LOOKUP_INDEX)) {
            DB::statement('ALTER TABLE '.self::TABLE.' ADD INDEX '.self::LOOKUP_INDEX.' (student_id, queue_date)');
        }

        if (LegacySchema::hasIndex(self::TABLE, self::OLD_UNIQUE)) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP INDEX '.self::OLD_UNIQUE);
        }

        if (! LegacySchema::hasColumn(self::TABLE, 'active_student_id')) {
            DB::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN active_student_id BIGINT UNSIGNED NULL');
        }

        // Derived from the status each row already has, so this writes nothing
        // that was not already true of the row.
        DB::statement('
            UPDATE '.self::TABLE.'
            SET active_student_id = CASE
                WHEN status IN ('.self::OPEN.') THEN student_id
                ELSE NULL
            END
        ');

        $this->assertNoOpenDuplicates();

        if (! LegacySchema::hasIndex(self::TABLE, self::GUARD_UNIQUE)) {
            DB::statement('ALTER TABLE '.self::TABLE.'
                ADD UNIQUE KEY '.self::GUARD_UNIQUE.' (active_student_id, queue_date)');
        }
    }

    public function down(): void
    {
        if (! LegacySchema::hasTable(self::TABLE)) {
            return;
        }

        if (LegacySchema::hasIndex(self::TABLE, self::GUARD_UNIQUE)) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP INDEX '.self::GUARD_UNIQUE);
        }

        if (LegacySchema::hasColumn(self::TABLE, 'active_student_id')) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP COLUMN active_student_id');
        }

        // Putting the old one-row-per-day rule back is only possible if no
        // student has since trained twice in a day. Say so plainly rather than
        // failing with a bare 1062 — and never by deleting a cycle.
        $repeats = DB::select('
            SELECT student_id, queue_date, COUNT(*) AS cycles
            FROM '.self::TABLE.'
            GROUP BY student_id, queue_date
            HAVING COUNT(*) > 1
        ');

        if ($repeats !== []) {
            throw new RuntimeException(
                'Cannot restore the one-queue-entry-per-day unique key: '.count($repeats).
                ' student/date pairs now hold more than one training cycle. Those rows are real history — '.
                'roll back to a backup taken before the repeat cycles were recorded instead of deleting them.'
            );
        }

        if (! LegacySchema::hasIndex(self::TABLE, self::OLD_UNIQUE)) {
            DB::statement('ALTER TABLE '.self::TABLE.'
                ADD UNIQUE KEY '.self::OLD_UNIQUE.' (student_id, queue_date)');
        }

        if (LegacySchema::hasIndex(self::TABLE, self::LOOKUP_INDEX)) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP INDEX '.self::LOOKUP_INDEX);
        }
    }

    /**
     * Two OPEN entries for one student on one date would make the new index
     * impossible to add. The index this migration drops forbade even that, so
     * on any real database this finds nothing — but a part-finished earlier run
     * of this same migration could leave some, and a bare 1062 mid-migration
     * would say nothing useful about which rows.
     */
    protected function assertNoOpenDuplicates(): void
    {
        $clashes = DB::select('
            SELECT active_student_id AS student_id, queue_date, GROUP_CONCAT(id) AS entries
            FROM '.self::TABLE.'
            WHERE active_student_id IS NOT NULL
            GROUP BY active_student_id, queue_date
            HAVING COUNT(*) > 1
        ');

        if ($clashes === []) {
            return;
        }

        $detail = implode('; ', array_map(
            fn ($row) => "student {$row->student_id} on {$row->queue_date}: entries {$row->entries}",
            $clashes,
        ));

        throw new RuntimeException(
            'Cannot add the active_student_id guard: a student has more than one open queue entry on the same '.
            'date. Complete or cancel the stale ones first, then re-run the migration. Affected — '.$detail
        );
    }
};
