<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockCache;
use App\Models\StockMovement;

/**
 * Updates product weighted average cost when stock-increasing movements are recorded.
 * Sales (sale_out) do not change average cost; only incoming movements do.
 */
class InventoryCostService
{
    private const COST_UPDATE_TYPES = [
        'purchase_in', 'adjustment_in', 'return_in', 'production_in', 'initial_stock',
    ];

    public function updateAverageCostIfApplicable(StockMovement $movement): void
    {
        $typeValue = $movement->type instanceof StockMovementType
            ? $movement->type->value
            : (string) $movement->type;

        if (! in_array($typeValue, self::COST_UPDATE_TYPES, true)) {
            return;
        }

        $incomingQty = (float) $movement->quantity;
        if ($incomingQty <= 0) {
            return;
        }

        $unitCost = $movement->unit_cost !== null ? (float) $movement->unit_cost : 0.0;
        if ($unitCost < 0) {
            return;
        }

        $product = Product::withoutGlobalScope('company')->find($movement->product_id);
        if (! $product) {
            return;
        }

        $cache = StockCache::where('product_id', $movement->product_id)
            ->where('warehouse_id', $movement->warehouse_id)
            ->where('variant_id', $movement->variant_id)
            ->first();

        $currentStock = $cache ? (float) $cache->quantity : 0.0;
        $currentAvgCost = $product->average_cost !== null ? (float) $product->average_cost : 0.0;

        if ($currentStock <= 0) {
            $newAvgCost = $unitCost;
        } else {
            $newAvgCost = (
                ($currentStock * $currentAvgCost) + ($incomingQty * $unitCost)
            ) / ($currentStock + $incomingQty);
        }

        $product->update(['average_cost' => round($newAvgCost, 4)]);
    }
}
