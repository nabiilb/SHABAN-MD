<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** In-app notifications (written by the Laravel "lab" notification channel) and the activity log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('user_id', 40);
            $t->enum('type', ['case_submitted', 'case_received', 'case_assigned', 'deadline_approaching', 'case_overdue', 'qc_required', 'qc_failed', 'case_ready', 'case_dispatched', 'case_delivered', 'correction_requested', 'payment_received']);
            $t->string('title', 200);
            $t->text('message');
            $t->string('case_id', 40)->nullable();
            $t->dateTime('read_at', 3)->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->index(['user_id', 'created_at']);
            $t->index(['user_id', 'read_at']);
            $t->index('case_id');
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();
        });

        Schema::create('activity_log', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('user_id', 40)->nullable();
            // Kept so the trail survives user deletion.
            $t->string('user_name', 160);
            $t->string('action', 60);
            $t->text('description');
            $t->string('subject_type', 30);
            $t->string('subject_id', 40)->nullable();
            $t->string('subject_label', 190)->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->index('created_at');
            $t->index(['subject_type', 'subject_id']);
            $t->index('user_id');
            $t->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('notifications');
    }
};
