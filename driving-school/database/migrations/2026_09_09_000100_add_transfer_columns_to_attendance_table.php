<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance is owned by the instructor recorded on the row, per date. When a
 * student is transferred, only that date's row moves to the new instructor;
 * these columns record where it came from so the move stays auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->foreignId('transferred_from_instructor_id')
                ->nullable()
                ->after('instructor_id')
                ->constrained('instructors')
                ->nullOnDelete();

            $table->foreignId('student_transfer_id')
                ->nullable()
                ->after('transferred_from_instructor_id')
                ->constrained('student_transfers')
                ->nullOnDelete();

            $table->timestamp('transferred_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropForeign(['transferred_from_instructor_id']);
            $table->dropForeign(['student_transfer_id']);
            $table->dropColumn(['transferred_from_instructor_id', 'student_transfer_id', 'transferred_at']);
        });
    }
};
