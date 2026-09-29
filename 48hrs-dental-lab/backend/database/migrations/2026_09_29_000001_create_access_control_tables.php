<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roles, the permission catalogue and the editable role → permission matrix. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->string('key', 40)->primary();
            $t->string('name', 120);
            $t->string('description', 300);
            // Super Admin: always every permission, not editable.
            $t->boolean('locked')->default(false);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->string('label', 160);
            $t->string('group', 60);
            $t->string('description', 300)->nullable();
        });

        Schema::create('role_permissions', function (Blueprint $t) {
            $t->string('role_key', 40);
            $t->string('permission_key', 64);
            $t->primary(['role_key', 'permission_key']);
            $t->index('permission_key');
            $t->foreign('role_key')->references('key')->on('roles')->cascadeOnDelete();
            $t->foreign('permission_key')->references('key')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
