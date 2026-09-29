<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Service price list, key/value lab settings and the atomic number sequences. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_services', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('name', 160);
            $t->enum('case_type', ['crown', 'bridge', 'veneer', 'implant', 'denture', 'appliance']);
            $t->enum('unit_mode', ['tooth', 'denture', 'arch']);
            $t->decimal('unit_price', 12, 2);
            $t->string('default_material', 120);
            $t->boolean('active')->default(true);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
        });

        // The "lab" key holds LabSettings as JSON (validated by the API).
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->json('value');
            $t->dateTime('updated_at', 3)->nullable();
        });

        // Counters for case, invoice and patient numbers; incremented under a row lock.
        Schema::create('sequences', function (Blueprint $t) {
            $t->string('name', 40)->primary();
            $t->unsignedInteger('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('lab_services');
    }
};
