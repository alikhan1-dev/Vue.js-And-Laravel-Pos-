<?php

namespace App\Services;

use App\Models\ProductBatch;
use Carbon\Carbon;

/**
 * FEFO: First Expiry First Out. Selects earliest valid batch for product/warehouse.
 */
class BatchAllocationService
{
    public function getEarliestValidBatch(int $productId, int $warehouseId): ?ProductBatch
    {
        $today = Carbon::today()->toDateString();

        return ProductBatch::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where(function ($q) use ($today): void {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
            })
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->first();
    }

    public function validateBatchForMovement(int $batchId, int $productId, int $warehouseId): bool
    {
        $batch = ProductBatch::find($batchId);
        if (! $batch) {
            return false;
        }
        if ($batch->product_id !== $productId || $batch->warehouse_id !== $warehouseId) {
            return false;
        }
        if ($batch->expiry_date !== null && $batch->expiry_date->lt(Carbon::today())) {
            return false;
        }
        return true;
    }
}
