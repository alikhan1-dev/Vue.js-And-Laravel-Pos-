<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('return_number', 50)->nullable();
            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->string('status', 20)->default('draft'); // draft, completed, cancelled
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'return_number']);
            $table->index(['company_id', 'status']);
            $table->index(['sale_id']);
            $table->index(['warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
