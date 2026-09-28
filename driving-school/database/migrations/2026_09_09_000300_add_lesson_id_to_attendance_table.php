<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lesson worked on that day belongs to that day's attendance record, so a
 * day carries its own lesson and rating rather than borrowing them from the
 * student's profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->foreignId('lesson_id')
                ->nullable()
                ->after('instructor_id')
                ->constrained('lessons')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropForeign(['lesson_id']);
            $table->dropColumn('lesson_id');
        });
    }
};
