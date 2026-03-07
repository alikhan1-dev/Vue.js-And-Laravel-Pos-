<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Landed cost allocation (shipping, duty, etc.) for accurate inventory valuation.
     * Enterprise ERPs snapshot and allocate these costs to receipt lines/products.
     */
    public function up(): void
    {
        Schema::create('landed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft, allocated, cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('purchase_id');
        });

        Schema::create('landed_cost_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landed_cost_id')->constrained('landed_costs')->cascadeOnDelete();
            $table->string('cost_type', 50); // shipping, duty, insurance, handling, etc.
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('allocation_method', 50)->nullable(); // quantity, value, weight, manual
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('landed_cost_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_lines');
        Schema::dropIfExists('landed_costs');
    }
};
