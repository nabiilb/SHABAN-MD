<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One invoice per received case; payments against it. amount_paid = Σ payments, kept in the same transaction. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('invoice_number', 30)->unique();
            $t->string('case_id', 40)->unique();
            $t->string('patient_id', 40);
            $t->string('doctor_id', 40);
            $t->string('clinic_id', 40);
            $t->decimal('subtotal', 12, 2);
            $t->decimal('emergency_fee', 12, 2);
            $t->decimal('discount', 12, 2)->default(0);
            $t->decimal('total', 12, 2);
            $t->decimal('amount_paid', 12, 2)->default(0);
            $t->dateTime('issued_at', 3);
            $t->dateTime('due_date', 3);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index('clinic_id');
            $t->index('doctor_id');
            $t->index('patient_id');
            $t->index('issued_at');
            $t->index('due_date');
            $t->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();
            $t->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $t->foreign('doctor_id')->references('id')->on('doctors')->restrictOnDelete();
            $t->foreign('clinic_id')->references('id')->on('clinics')->restrictOnDelete();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('invoice_id', 40);
            $t->decimal('amount', 12, 2);
            $t->enum('method', ['cash', 'bank_transfer', 'mobile_money', 'card', 'other']);
            // A transaction reference identifies one real payment: unique across all invoices, compared exactly
            // (binary collation). NULL when there is none (cash) — NULLs never collide in a unique index.
            $t->string('reference', 120)->collation('utf8mb4_bin')->nullable()->unique();
            $t->text('notes')->nullable();
            $t->string('received_by_id', 40);
            $t->dateTime('paid_at', 3);
            $t->dateTime('created_at', 3)->nullable();
            $t->index('invoice_id');
            $t->index('paid_at');
            $t->index('method');
            $t->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $t->foreign('received_by_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
    }
};
