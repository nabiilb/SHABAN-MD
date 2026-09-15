<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_records', function (Blueprint $table) {
            $table->id();
            $table->string('fuel_number', 30)->unique();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('instructor_id')->nullable()->constrained('instructors')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('company_expense_id')->nullable()->constrained('company_expenses')->nullOnDelete();
            $table->foreignId('company_debt_id')->nullable()->constrained('company_debts')->nullOnDelete();
            $table->decimal('liters', 10, 2);
            $table->decimal('price_per_liter', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('odometer')->nullable();
            $table->date('fuel_date');
            $table->boolean('is_credit')->default(false);
            $table->enum('payment_method', ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'other'])->default('cash');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_id', 'fuel_date']);
            $table->index('fuel_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_records');
    }
};
