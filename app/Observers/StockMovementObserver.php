<?php

namespace App\Observers;

use App\Events\NegativeStockDetected;
use App\Events\StockMovementCreated;
use App\Models\BatchStockCache;
use App\Models\Product;
use App\Models\StockCache;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\InventoryAlertService;
use App\Services\InventoryCostService;
use App\Services\InventoryAuditLogService;
use App\Services\InventoryJournalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps stock_cache in sync when a new StockMovement is created (movements are immutable).
 * Updates weighted average cost for stock-increasing movements before cache update.
 */
class StockMovementObserver
{
    public function created(StockMovement $movement): void
    {
        // Update product average cost for incoming movements (before cache reflects the new stock).
        app(InventoryCostService::class)->updateAverageCostIfApplicable($movement);

        $delta = (float) $movement->quantity; // signed quantity
        $productId = $movement->product_id;
        $warehouseId = $movement->warehouse_id;
        $variantId = $movement->variant_id;

        $cacheRow = StockCache::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->first();

        $oldQuantity = $cacheRow ? (float) $cacheRow->quantity : 0.0;
        $newQuantity = $oldQuantity + $delta;

        if ($cacheRow) {
            DB::table('stock_cache')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->where('variant_id', $variantId)
                ->update([
                    'quantity' => DB::raw("quantity + ({$delta})"),
                    'updated_at' => now(),
                ]);
        } else {
            StockCache::create([
                'company_id' => $movement->company_id,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'variant_id' => $variantId,
                'quantity' => $delta,
            ]);
        }

        app(InventoryAuditLogService::class)->logMovementCreated($movement, $oldQuantity, $newQuantity);

        $this->syncBatchStockCache($movement, $delta);

        if ($newQuantity < 0) {
            $product = Product::withoutGlobalScope('company')->find($movement->product_id);
            $warehouse = Warehouse::find($movement->warehouse_id);
            if ($product && $warehouse) {
                NegativeStockDetected::dispatch($product, $warehouse, $newQuantity);
                app(InventoryAlertService::class)->negativeStock($product, $warehouse, $newQuantity);
            }
        }

        StockMovementCreated::dispatch($movement);

        app(InventoryJournalService::class)->postFromMovement($movement);
    }

    private function syncBatchStockCache(StockMovement $movement, float $delta): void
    {
        if (! $movement->batch_id) {
            return;
        }

        if (! Schema::hasTable('batch_stock_cache')) {
            return;
        }

        $row = BatchStockCache::where('batch_id', $movement->batch_id)
            ->where('product_id', $movement->product_id)
            ->where('warehouse_id', $movement->warehouse_id)
            ->first();

        if ($row) {
            DB::table('batch_stock_cache')
                ->where('batch_id', $movement->batch_id)
                ->where('product_id', $movement->product_id)
                ->where('warehouse_id', $movement->warehouse_id)
                ->update([
                    'quantity' => DB::raw("quantity + ({$delta})"),
                    'updated_at' => now(),
                ]);
        } else {
            BatchStockCache::create([
                'company_id' => $movement->company_id,
                'batch_id' => $movement->batch_id,
                'product_id' => $movement->product_id,
                'warehouse_id' => $movement->warehouse_id,
                'quantity' => $delta,
            ]);
        }
    }
}
