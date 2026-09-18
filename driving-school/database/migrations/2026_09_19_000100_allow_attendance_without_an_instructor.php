<?php

use App\Support\LegacySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A day's attendance can be recorded for a student nobody is assigned to.
 *
 * `attendance.instructor_id` was NOT NULL, which is right for a check-in: a
 * teacher checked them in, so there is a teacher. It is wrong for the daily
 * absence marking, where the school records that a student did NOT attend and
 * there is no teacher to name — and a great many students have no permanent
 * instructor at all, the register import among them.
 *
 * The alternative was to leave those students out of the absence marking, which
 * would quietly make the feature untrue for most of the school.
 *
 * Widening NOT NULL to NULL is the safest schema change there is: every
 * existing row still satisfies the column, nothing is read, nothing is written,
 * and no value is lost. Idempotent — running it twice leaves the same column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! LegacySchema::hasTable('attendance') || $this->alreadyNullable()) {
            return;
        }

        // Dropping and re-adding the foreign key around the change, because
        // MySQL will not modify a column a constraint is sitting on.
        $this->withoutForeignKey(fn () => DB::statement(
            'ALTER TABLE attendance MODIFY instructor_id BIGINT UNSIGNED NULL'
        ));
    }

    public function down(): void
    {
        if (! LegacySchema::hasTable('attendance') || ! $this->alreadyNullable()) {
            return;
        }

        // Only reversible while no row relies on the column being empty; an
        // absence recorded for an unassigned student does.
        $orphans = DB::table('attendance')->whereNull('instructor_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot make attendance.instructor_id NOT NULL again: {$orphans} attendance row(s) ".
                'were recorded for students with no instructor. Those are real records — assign an '.
                'instructor to them, or stay on this migration.'
            );
        }

        $this->withoutForeignKey(fn () => DB::statement(
            'ALTER TABLE attendance MODIFY instructor_id BIGINT UNSIGNED NOT NULL'
        ));
    }

    protected function alreadyNullable(): bool
    {
        $column = collect(DB::select('SHOW COLUMNS FROM `attendance`'))
            ->firstWhere('Field', 'instructor_id');

        return $column !== null && strtoupper((string) ($column->Null ?? '')) === 'YES';
    }

    /** Runs the change with the foreign key lifted, then puts it back. */
    protected function withoutForeignKey(callable $change): void
    {
        $key = collect(DB::select('SHOW CREATE TABLE `attendance`'))
            ->pluck('Create Table')
            ->first();

        $name = preg_match('/CONSTRAINT `([^`]+)` FOREIGN KEY \(`instructor_id`\)/', (string) $key, $m)
            ? $m[1]
            : null;

        if ($name) {
            DB::statement("ALTER TABLE attendance DROP FOREIGN KEY `{$name}`");
        }

        $change();

        if ($name) {
            DB::statement('ALTER TABLE attendance
                ADD CONSTRAINT `'.$name.'` FOREIGN KEY (instructor_id)
                REFERENCES instructors (id) ON DELETE CASCADE');
        }
    }
};
