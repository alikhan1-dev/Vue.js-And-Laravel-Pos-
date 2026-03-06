<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Materialized batch-level stock cache.
     * Avoids SUM(stock_movements) per batch on every read when batches grow large.
     */
    public function up(): void
    {
        Schema::create('batch_stock_cache', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('product_batches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['batch_id', 'product_id', 'warehouse_id'], 'batch_stock_cache_unique');
            $table->index(['product_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_stock_cache');
    }
};
