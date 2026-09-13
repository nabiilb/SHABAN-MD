<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A day-scoped hand-over. It says who owns a student's attendance on one
 * particular date and nothing more: the student's permanent instructor is
 * untouched, so the next day they are back with them automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->foreignId('from_instructor_id')->nullable()->constrained('instructors')->nullOnDelete();
            $table->foreignId('to_instructor_id')->constrained('instructors')->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One hand-over per student per day keeps ownership unambiguous.
            $table->unique(['student_id', 'attendance_date']);
            $table->index(['to_instructor_id', 'attendance_date']);
            $table->index(['from_instructor_id', 'attendance_date']);
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->foreignId('attendance_transfer_id')
                ->nullable()
                ->after('student_transfer_id')
                ->constrained('attendance_transfers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropForeign(['attendance_transfer_id']);
            $table->dropColumn('attendance_transfer_id');
        });

        Schema::dropIfExists('attendance_transfers');
    }
};
