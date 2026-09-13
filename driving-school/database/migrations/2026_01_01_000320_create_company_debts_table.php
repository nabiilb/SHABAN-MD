<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_debts', function (Blueprint $table) {
            $table->id();
            $table->string('debt_number', 30)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('description');
            $table->decimal('original_amount', 12, 2);
            $table->decimal('remaining_amount', 12, 2);
            $table->date('debt_date');
            $table->date('due_date')->nullable();
            $table->enum('status', ['outstanding', 'partially_paid', 'paid', 'overdue', 'cancelled'])
                ->default('outstanding')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_id', 'status']);
            $table->index('debt_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_debts');
    }
};
