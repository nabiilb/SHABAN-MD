<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One training session. started_at and expected_end_at are the authority for
 * the countdown — the browser only renders what these say, so a refresh or a
 * second screen cannot drift.
 *
 * The session's own clock columns are DATETIME rather than TIMESTAMP. With
 * MySQL's explicit_defaults_for_timestamp OFF (the XAMPP default), a second
 * TIMESTAMP NOT NULL column in the same table is silently given
 * DEFAULT '0000-00-00 00:00:00', which strict mode then rejects with
 * "Invalid default value". DATETIME NOT NULL needs no default at all, which is
 * right here: the service always supplies both values when a session starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('training_sessions')) {
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

                // Always written by TrainingSessionService::start(), so both are
                // NOT NULL with no default.
                $table->dateTime('started_at');
                $table->dateTime('expected_end_at');

                // Only set once the session ends or is paused.
                $table->dateTime('ended_at')->nullable();
                $table->dateTime('paused_at')->nullable();

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
        }

        // A hard guarantee that a student can never have two live sessions:
        // the generated column holds the student id only while the session is
        // live, and NULLs do not collide in a unique index.
        //
        // Guarded so that if a previous attempt created the table but stopped
        // before this statement, re-running the migration finishes the job
        // rather than failing on "table already exists".
        if (! Schema::hasColumn('training_sessions', 'active_student_id')) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('training_sessions');
    }
};
