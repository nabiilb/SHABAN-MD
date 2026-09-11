<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The waiting line for a given day. Position is what the queue is ordered by;
 * status is what the dashboards show for the student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('queue_date');
            $table->unsignedInteger('position');
            $table->enum('status', [
                'waiting', 'training_in_progress', 'attendance_pending', 'completed', 'cancelled',
            ])->default('waiting');
            $table->foreignId('preferred_instructor_id')->nullable()->constrained('instructors')->nullOnDelete();
            $table->unsignedSmallInteger('assigned_duration_minutes')->nullable();
            $table->timestamp('joined_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A student can only be in a given day's queue once.
            $table->unique(['student_id', 'queue_date']);
            $table->index(['queue_date', 'status', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_queue_entries');
    }
};
