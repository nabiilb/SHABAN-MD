<?php

use App\Support\LegacySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuel now needs an approval state, because instructors can record it.
 *
 * Recording fuel is not a note — it writes into the company ledger, either a
 * company expense (cash) or a company debt (credit). While only admins could
 * do that, posting on save was right and no state was needed. An instructor
 * submitting fuel must not move company money unreviewed, so a submission now
 * waits as `pending` and posts nothing until an admin approves it.
 *
 * Additive and non-destructive. `status` defaults to `approved`, so every row
 * already in the table, and everything an admin records from here on, behaves
 * exactly as before — the admin flow is unchanged, not re-routed through a
 * queue. `submission_token` is the idempotency key: a unique index means a
 * double-clicked or retried form cannot post the same fuel twice, and NULLs do
 * not collide, so the admin forms that do not send one are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! LegacySchema::hasTable('fuel_records')) {
            return;
        }

        Schema::table('fuel_records', function (Blueprint $table) {
            if (! LegacySchema::hasColumn('fuel_records', 'status')) {
                $table->enum('status', ['pending', 'approved', 'rejected'])
                    ->default('approved')
                    ->after('is_credit')
                    ->index();
            }

            if (! LegacySchema::hasColumn('fuel_records', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('created_by')
                    ->constrained('users')->nullOnDelete();
            }

            if (! LegacySchema::hasColumn('fuel_records', 'approved_at')) {
                // DATETIME, not TIMESTAMP: see the training tables for why.
                $table->dateTime('approved_at')->nullable()->after('approved_by');
            }

            if (! LegacySchema::hasColumn('fuel_records', 'rejection_reason')) {
                $table->string('rejection_reason', 500)->nullable()->after('approved_at');
            }

            if (! LegacySchema::hasColumn('fuel_records', 'submission_token')) {
                $table->char('submission_token', 36)->nullable()->after('rejection_reason');
                $table->unique('submission_token', 'fuel_records_submission_token_unique');
            }
        });
    }

    public function down(): void
    {
        if (! LegacySchema::hasTable('fuel_records')) {
            return;
        }

        Schema::table('fuel_records', function (Blueprint $table) {
            if (LegacySchema::hasIndex('fuel_records', 'fuel_records_submission_token_unique')) {
                $table->dropUnique('fuel_records_submission_token_unique');
            }

            if (LegacySchema::hasColumn('fuel_records', 'approved_by')) {
                $table->dropConstrainedForeignId('approved_by');
            }

            foreach (['status', 'approved_at', 'rejection_reason', 'submission_token'] as $column) {
                if (LegacySchema::hasColumn('fuel_records', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
