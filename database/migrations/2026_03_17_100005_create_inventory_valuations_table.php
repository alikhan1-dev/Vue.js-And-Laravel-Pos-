<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_valuations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->date('valuation_date');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('total_value', 18, 4)->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'product_id', 'warehouse_id', 'variant_id', 'valuation_date'], 'inv_val_unique');
            $table->index(['company_id', 'valuation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_valuations');
    }
};
