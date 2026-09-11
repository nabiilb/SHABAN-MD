<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One training session. started_at and expected_end_at are the authority for
 * the countdown — the browser only renders what these say, so a refresh or a
 * second screen cannot drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('instructors')->cascadeOnDelete();
            $table->foreignId('training_queue_entry_id')->nullable()->constrained('training_queue_entries')->nullOnDelete();
            $table->foreignId('lesson_topic_id')->nullable()->constrained('lesson_topics')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            $table->enum('status', ['in_progress', 'paused', 'attendance_pending', 'completed', 'cancelled'])
                ->default('in_progress');

            $table->unsignedSmallInteger('assigned_duration_minutes');
            $table->unsignedSmallInteger('extended_minutes')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('expected_end_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->boolean('ended_early')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['instructor_id', 'status']);
            $table->index(['student_id', 'started_at']);
            $table->index('started_at');
        });

        // A hard guarantee that a student can never have two live sessions:
        // the generated column holds the student id only while the session is
        // live, and NULLs do not collide in a unique index.
        DB::statement("
            ALTER TABLE training_sessions
            ADD COLUMN active_student_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN status IN ('in_progress', 'paused', 'attendance_pending')
                         THEN student_id ELSE NULL END
                ) STORED,
            ADD UNIQUE KEY training_sessions_active_student_unique (active_student_id)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('training_sessions');
    }
};
