<?php

use App\Support\LegacySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A teacher can only run one training session at a time — and the guard
 * columns that say so must work on MySQL 5.5.
 *
 * The first version of this guard used a generated column:
 *
 *     ADD COLUMN active_instructor_id BIGINT UNSIGNED
 *         GENERATED ALWAYS AS (CASE WHEN status IN (...) THEN instructor_id END) STORED
 *
 * which needs MySQL 5.7.6 / MariaDB 10.2, and it guarded itself with
 * Schema::hasColumn(), which needs information_schema.columns.generation_expression
 * — also 5.7. On MySQL 5.5 the guard failed before the DDL was even reached:
 * "1054 Unknown column 'generation_expression' in 'field list'".
 *
 * So both guard columns are now plain nullable columns that TrainingSession
 * keeps in step with status, under the same unique indexes as before. The
 * database-level guarantee is unchanged — a unique index still refuses a second
 * live session for the same student or the same teacher, and NULLs still do not
 * collide — but nothing here is newer than MySQL 5.5.
 *
 * This migration is additive and idempotent. It converts a pair of generated
 * columns created by the earlier version (on a server new enough to have taken
 * them), adds whatever is missing, and recomputes both values from status. No
 * row is deleted and no column holding real data is touched: the guard columns
 * are derived from status, student_id and instructor_id, so dropping and
 * rebuilding one loses nothing.
 */
return new class extends Migration
{
    /** Guard column => the column it mirrors while a session is live. */
    protected const GUARDS = [
        'active_student_id' => ['student_id', 'training_sessions_active_student_unique'],
        'active_instructor_id' => ['instructor_id', 'training_sessions_active_instructor_unique'],
    ];

    protected const LIVE = "'in_progress', 'paused', 'attendance_pending'";

    public function up(): void
    {
        if (! LegacySchema::hasTable('training_sessions')) {
            return;
        }

        foreach (self::GUARDS as $guard => [$source, $index]) {
            $this->dropIfGenerated($guard, $index);
            $this->addPlainColumn($guard);
            $this->backfill($guard, $source);
        }

        // The values have to be right before the indexes go on, or a legitimate
        // duplicate would surface as a bare 1062 in the middle of a migration.
        foreach (self::GUARDS as $guard => [$source, $index]) {
            $this->assertNoDuplicates($guard, $source);
            $this->addUniqueIndex($guard, $index);
        }
    }

    public function down(): void
    {
        if (! LegacySchema::hasTable('training_sessions')) {
            return;
        }

        // Only the teacher guard belongs to this migration; the student guard
        // is the create-table migration's to drop.
        [, $index] = self::GUARDS['active_instructor_id'];

        if (LegacySchema::hasIndex('training_sessions', $index)) {
            DB::statement("ALTER TABLE training_sessions DROP INDEX {$index}");
        }

        if (LegacySchema::hasColumn('training_sessions', 'active_instructor_id')) {
            DB::statement('ALTER TABLE training_sessions DROP COLUMN active_instructor_id');
        }
    }

    /**
     * A generated column cannot be written to, so it has to go before the plain
     * one can take its place. Nothing is lost — backfill() recomputes exactly
     * what the generation expression produced.
     */
    protected function dropIfGenerated(string $guard, string $index): void
    {
        if (! LegacySchema::isGeneratedColumn('training_sessions', $guard)) {
            return;
        }

        if (LegacySchema::hasIndex('training_sessions', $index)) {
            DB::statement("ALTER TABLE training_sessions DROP INDEX {$index}");
        }

        DB::statement("ALTER TABLE training_sessions DROP COLUMN {$guard}");
    }

    protected function addPlainColumn(string $guard): void
    {
        if (LegacySchema::hasColumn('training_sessions', $guard)) {
            return;
        }

        DB::statement("ALTER TABLE training_sessions ADD COLUMN {$guard} BIGINT UNSIGNED NULL");
    }

    protected function backfill(string $guard, string $source): void
    {
        // A soft-deleted session is not occupying anybody either.
        DB::statement("
            UPDATE training_sessions
            SET {$guard} = CASE
                WHEN status IN (".self::LIVE.') AND deleted_at IS NULL THEN '.$source.'
                ELSE NULL
            END
        ');
    }

    /**
     * Two live sessions for the same student (or teacher) would make the unique
     * index impossible to add. That is a real inconsistency in the data, so say
     * exactly which rows it is rather than letting ALTER TABLE fail with 1062.
     */
    protected function assertNoDuplicates(string $guard, string $source): void
    {
        $clashes = DB::select("
            SELECT {$guard} AS owner, GROUP_CONCAT(id) AS sessions
            FROM training_sessions
            WHERE {$guard} IS NOT NULL
            GROUP BY {$guard}
            HAVING COUNT(*) > 1
        ");

        if ($clashes === []) {
            return;
        }

        $detail = implode('; ', array_map(
            fn ($row) => "{$source} {$row->owner}: training_sessions ".$row->sessions,
            $clashes,
        ));

        throw new RuntimeException(
            "Cannot add the {$guard} guard: more than one live training session shares the same {$source}. "
            ."Close or cancel the stale ones first, then re-run the migration. Affected — {$detail}"
        );
    }

    protected function addUniqueIndex(string $guard, string $index): void
    {
        if (LegacySchema::hasIndex('training_sessions', $index)) {
            return;
        }

        DB::statement("ALTER TABLE training_sessions ADD UNIQUE KEY {$index} ({$guard})");
    }
};
