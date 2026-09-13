<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_number', 30)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('full_name');
            $table->string('phone', 30);
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('license_type', 60)->nullable();
            $table->date('start_date');
            $table->unsignedSmallInteger('required_training_days')->default(24);
            $table->foreignId('current_instructor_id')->nullable()->constrained('instructors')->nullOnDelete();
            $table->enum('status', ['active', 'completed', 'suspended', 'cancelled'])->default('active');
            $table->date('completion_date')->nullable();
            $table->decimal('total_fee', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('profile_photo')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['current_instructor_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
