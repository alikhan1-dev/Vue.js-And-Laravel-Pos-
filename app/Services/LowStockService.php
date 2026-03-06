<?php

namespace App\Services;

use App\Models\StockCache;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Detects low stock (quantity <= reorder_level) and returns purchase suggestions.
 * Used for reorder reports and automated purchase suggestions.
 */
class LowStockService
{
    /**
     * Get all cache rows where quantity <= reorder_level (and reorder_level > 0).
     *
     * @return Collection<int, array{stock_cache: StockCache, product: Product, suggested_qty: float}>
     */
    public function getLowStockItems(?int $companyId = null, ?int $warehouseId = null): Collection
    {
        $query = StockCache::with('product')
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->where('reorder_level', '>', 0);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }
        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->get()->map(function (StockCache $cache) {
            $reorderQty = (float) ($cache->reorder_quantity ?? 0);
            $suggested = $reorderQty > 0 ? $reorderQty : max(0, (float) $cache->reorder_level - (float) $cache->quantity);

            return [
                'stock_cache' => $cache,
                'product' => $cache->product,
                'suggested_qty' => $suggested,
            ];
        });
    }

    /**
     * Check if a product at a warehouse is below reorder level.
     */
    public function isBelowReorderLevel(int $productId, int $warehouseId, ?int $variantId = null): bool
    {
        $cache = StockCache::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->first();

        if (! $cache || (float) $cache->reorder_level <= 0) {
            return false;
        }

        return (float) $cache->quantity <= (float) $cache->reorder_level;
    }
}
