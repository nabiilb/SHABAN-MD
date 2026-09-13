<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('from_instructor_id')->nullable()->constrained('instructors')->nullOnDelete();
            $table->foreignId('to_instructor_id')->constrained('instructors')->cascadeOnDelete();
            $table->date('transfer_date');
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'transfer_date']);
            $table->index('from_instructor_id');
            $table->index('to_instructor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_transfers');
    }
};
