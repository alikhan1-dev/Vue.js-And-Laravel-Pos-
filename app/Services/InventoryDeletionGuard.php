<?php

namespace App\Services;

use App\Exceptions\CannotDeleteEntityWithMovementsException;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;

/**
 * Prevents deletion of critical inventory entities when stock movements reference them.
 * Ensures audit trail integrity: movements must always resolve to valid product/warehouse/batch/variant.
 */
class InventoryDeletionGuard
{
    /**
     * @throws CannotDeleteEntityWithMovementsException
     */
    public function ensureProductCanBeDeleted(Product $product): void
    {
        $exists = StockMovement::withoutGlobalScope('company')
            ->where('product_id', $product->id)
            ->exists();

        if ($exists) {
            throw new CannotDeleteEntityWithMovementsException(
                'product',
                "id={$product->id}, sku={$product->sku}"
            );
        }
    }

    /**
     * @throws CannotDeleteEntityWithMovementsException
     */
    public function ensureWarehouseCanBeDeleted(Warehouse $warehouse): void
    {
        $exists = StockMovement::withoutGlobalScope('company')
            ->where('warehouse_id', $warehouse->id)
            ->exists();

        if ($exists) {
            throw new CannotDeleteEntityWithMovementsException(
                'warehouse',
                "id={$warehouse->id}, code={$warehouse->code}"
            );
        }
    }

    /**
     * @throws CannotDeleteEntityWithMovementsException
     */
    public function ensureBatchCanBeDeleted(ProductBatch $batch): void
    {
        $exists = StockMovement::withoutGlobalScope('company')
            ->where('batch_id', $batch->id)
            ->exists();

        if ($exists) {
            throw new CannotDeleteEntityWithMovementsException(
                'batch',
                "id={$batch->id}, batch_number={$batch->batch_number}"
            );
        }
    }

    /**
     * @throws CannotDeleteEntityWithMovementsException
     */
    public function ensureVariantCanBeDeleted(ProductVariant $variant): void
    {
        $exists = StockMovement::withoutGlobalScope('company')
            ->where('variant_id', $variant->id)
            ->exists();

        if ($exists) {
            throw new CannotDeleteEntityWithMovementsException(
                'variant',
                "id={$variant->id}, sku={$variant->sku}"
            );
        }
    }

    /**
     * Returns true if the entity can be deleted (no movements reference it).
     */
    public function canDeleteProduct(Product $product): bool
    {
        return ! StockMovement::withoutGlobalScope('company')
            ->where('product_id', $product->id)
            ->exists();
    }

    public function canDeleteWarehouse(Warehouse $warehouse): bool
    {
        return ! StockMovement::withoutGlobalScope('company')
            ->where('warehouse_id', $warehouse->id)
            ->exists();
    }

    public function canDeleteBatch(ProductBatch $batch): bool
    {
        return ! StockMovement::withoutGlobalScope('company')
            ->where('batch_id', $batch->id)
            ->exists();
    }

    public function canDeleteVariant(ProductVariant $variant): bool
    {
        return ! StockMovement::withoutGlobalScope('company')
            ->where('variant_id', $variant->id)
            ->exists();
    }
}
