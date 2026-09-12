<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A teacher can only run one training session at a time.
 *
 * This mirrors the per-student guard already on this table: the generated
 * column carries the instructor id only while a session is live, and NULLs do
 * not collide in a unique index — so the database itself refuses a second live
 * session for the same teacher, whatever the application does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('training_sessions', 'active_instructor_id')) {
            return;
        }

        DB::statement("
            ALTER TABLE training_sessions
            ADD COLUMN active_instructor_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN status IN ('in_progress', 'paused', 'attendance_pending')
                         THEN instructor_id ELSE NULL END
                ) STORED,
            ADD UNIQUE KEY training_sessions_active_instructor_unique (active_instructor_id)
        ");
    }

    public function down(): void
    {
        if (! Schema::hasColumn('training_sessions', 'active_instructor_id')) {
            return;
        }

        DB::statement('ALTER TABLE training_sessions
            DROP INDEX training_sessions_active_instructor_unique,
            DROP COLUMN active_instructor_id');
    }
};
