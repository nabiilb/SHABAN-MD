<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Laravel infrastructure: database sessions, password-reset tokens, cache/locks and the queue. */
return new class extends Migration
{
    public function up(): void
    {
        // One row per browser session (SESSION_DRIVER=database). Deleting a user's rows ends their sign-ins.
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('user_id', 40)->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        // Laravel's password broker: the token is stored hashed, one per e-mail, expires after auth.passwords.users.expire.
        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email', 190)->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $t) {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->integer('total_jobs');
            $t->integer('pending_jobs');
            $t->integer('failed_jobs');
            $t->longText('failed_job_ids');
            $t->mediumText('options')->nullable();
            $t->integer('cancelled_at')->nullable();
            $t->integer('created_at');
            $t->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['failed_jobs', 'job_batches', 'jobs', 'cache_locks', 'cache', 'password_reset_tokens', 'sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
