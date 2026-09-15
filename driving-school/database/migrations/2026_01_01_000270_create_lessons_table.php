<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('instructors')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('lesson_topic_id')->constrained('lesson_topics');
            $table->date('lesson_date');
            $table->string('topic')->nullable();
            $table->enum('performance', ['excellent', 'good', 'average', 'needs_improvement', 'poor'])->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->text('notes')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('completed');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'lesson_date']);
            $table->index(['instructor_id', 'lesson_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
