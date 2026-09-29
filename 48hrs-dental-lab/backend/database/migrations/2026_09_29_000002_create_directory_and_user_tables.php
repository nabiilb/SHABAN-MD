<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Clinics, doctors, users (logins), technicians and patients. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinics', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('name', 160);
            $t->string('contact_person', 120)->default('');
            $t->string('phone', 40);
            $t->string('email', 190)->default('');
            $t->string('address', 300)->default('');
            $t->enum('status', ['active', 'inactive'])->default('active');
            $t->text('notes')->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index('name');
            $t->index('status');
        });

        Schema::create('doctors', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('name', 160);
            $t->string('clinic_id', 40);
            $t->string('phone', 40);
            $t->string('email', 190)->default('');
            $t->string('specialty', 120)->default('');
            $t->enum('status', ['active', 'inactive'])->default('active');
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index('clinic_id');
            $t->index('name');
            $t->index('status');
            $t->foreign('clinic_id')->references('id')->on('clinics')->restrictOnDelete();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('name', 160);
            // Stored lower-case.
            $t->string('email', 190)->unique();
            $t->string('phone', 40)->default('');
            // bcrypt/argon hash from Laravel's Hash facade. Never plain text.
            $t->string('password', 255);
            $t->string('role_key', 40);
            $t->boolean('active')->default(true);
            // Client-portal users: the clinic whose cases they may see.
            $t->string('clinic_id', 40)->nullable();
            // Doctors who log into the client portal.
            $t->string('doctor_id', 40)->nullable();
            $t->dateTime('last_login_at', 3)->nullable();
            // Consecutive failed sign-ins; reset on success. In the database so lockout holds across PHP processes.
            $t->unsignedInteger('failed_logins')->default(0);
            $t->dateTime('locked_until', 3)->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index('role_key');
            $t->index('clinic_id');
            $t->foreign('role_key')->references('key')->on('roles')->restrictOnDelete();
            $t->foreign('clinic_id')->references('id')->on('clinics')->nullOnDelete();
            $t->foreign('doctor_id')->references('id')->on('doctors')->nullOnDelete();
        });

        Schema::create('technicians', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('user_id', 40)->nullable()->unique();
            $t->string('name', 160);
            $t->string('email', 190)->unique();
            $t->string('phone', 40);
            $t->string('specialty', 120);
            $t->boolean('active')->default(true);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index('name');
            $t->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('patients', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            // Human-readable reference, e.g. PT-1024.
            $t->string('code', 40)->unique();
            $t->string('name', 160);
            $t->string('phone', 40)->default('');
            $t->string('email', 190)->default('');
            $t->enum('gender', ['male', 'female'])->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('clinic_id', 40)->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index('name');
            $t->index('phone');
            $t->index('clinic_id');
            $t->foreign('clinic_id')->references('id')->on('clinics')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
        Schema::dropIfExists('technicians');
        Schema::dropIfExists('users');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('clinics');
    }
};
