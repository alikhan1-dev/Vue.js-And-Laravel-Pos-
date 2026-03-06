<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cached stock per product/warehouse for fast reads (heavy POS).
     * Updated by observer on StockMovement::created; source of truth remains movements.
     */
    public function up(): void
    {
        Schema::create('stock_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('reserved_quantity', 15, 2)->default(0);
            $table->decimal('reorder_level', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'variant_id', 'warehouse_id']);
            $table->index('warehouse_id');
        });

        $this->backfillFromMovements();
    }

    /**
     * Backfill cache from existing stock_movements.
     */
    private function backfillFromMovements(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("
                INSERT INTO stock_cache (product_id, warehouse_id, quantity, created_at, updated_at)
                SELECT product_id, warehouse_id,
                    SUM(quantity),
                    NOW(), NOW()
                FROM stock_movements
                GROUP BY product_id, warehouse_id
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()
            ");
            return;
        }

        if ($driver === 'sqlite') {
            $rows = DB::table('stock_movements')
                ->selectRaw("product_id, warehouse_id, SUM(CASE WHEN type IN ('purchase_in','transfer_in','adjustment_in') THEN quantity WHEN type IN ('sale_out','transfer_out','adjustment_out') THEN -quantity ELSE 0 END) as quantity")
                ->groupBy('product_id', 'warehouse_id')
                ->get();
            foreach ($rows as $row) {
                DB::table('stock_cache')->insertOrIgnore([
                    'product_id' => $row->product_id,
                    'warehouse_id' => $row->warehouse_id,
                    'quantity' => $row->quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_cache');
    }
};
