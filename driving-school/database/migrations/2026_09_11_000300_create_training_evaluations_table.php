<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's verdict on a finished session. One per session, enforced by a
 * unique key, so a double submit cannot produce two records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_id')->unique()->constrained('training_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('instructors')->cascadeOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('attendance')->nullOnDelete();

            $table->enum('attendance_status', ['present', 'absent', 'excused', 'cancelled'])->default('present');
            $table->enum('evaluation', ['excellent', 'very_good', 'good', 'needs_improvement'])->nullable();
            $table->text('comment')->nullable();
            // DATETIME, not TIMESTAMP: with explicit_defaults_for_timestamp OFF
            // the first TIMESTAMP NOT NULL column in a table is silently given
            // ON UPDATE CURRENT_TIMESTAMP, which would rewrite the evaluation
            // time on any later edit of the row. The service sets this once.
            $table->dateTime('evaluated_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['instructor_id', 'evaluated_at']);
            $table->index(['student_id', 'evaluated_at']);
            $table->index('evaluation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_evaluations');
    }
};
