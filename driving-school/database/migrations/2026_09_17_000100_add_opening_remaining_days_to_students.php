<?php

use App\Support\LegacySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An opening balance of training days, for students who arrived with a history
 * this system never saw.
 *
 * Remaining days are normally `required_training_days - completed_days`, where
 * completed_days counts attendance. That is right for a student who registered
 * here: every day they trained is a row. It is wrong for the register import,
 * where the school has been teaching somebody for two months and the ledger
 * this application can see is empty — the arithmetic would say the whole course
 * is still to run.
 *
 * So the register's own remaining-days figure is stored as an opening balance:
 *
 *   opening_remaining_days  the number the register gave, on the day it gave it
 *   opening_remaining_from  the date that number was true
 *
 * and the student's remaining days become that number less the training days
 * recorded from that date onwards. Nothing historical is fabricated: the days
 * before the opening date are represented by the number itself, which is what
 * the school counted them as.
 *
 * Both columns are nullable and mean "this student has no opening balance",
 * which is every student registered through the application. They keep the
 * attendance-based calculation exactly as it is.
 *
 * Additive and idempotent. No existing row is read or written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! LegacySchema::hasTable('students')) {
            return;
        }

        if (! LegacySchema::hasColumn('students', 'opening_remaining_days')) {
            DB::statement('ALTER TABLE students ADD COLUMN opening_remaining_days SMALLINT UNSIGNED NULL');
        }

        if (! LegacySchema::hasColumn('students', 'opening_remaining_from')) {
            DB::statement('ALTER TABLE students ADD COLUMN opening_remaining_from DATE NULL');
        }
    }

    public function down(): void
    {
        if (! LegacySchema::hasTable('students')) {
            return;
        }

        foreach (['opening_remaining_days', 'opening_remaining_from'] as $column) {
            if (LegacySchema::hasColumn('students', $column)) {
                DB::statement("ALTER TABLE students DROP COLUMN {$column}");
            }
        }
    }
};
