<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('instructors')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->time('check_in_time')->nullable();
            $table->enum('status', ['present', 'absent', 'excused', 'cancelled'])->default('present');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'attendance_date']);
            $table->index(['instructor_id', 'attendance_date']);
            $table->index(['attendance_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
