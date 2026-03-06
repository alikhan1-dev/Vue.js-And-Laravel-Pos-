<?php

namespace App\Services;

use App\Models\InventoryAlert;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Warehouse;

class InventoryAlertService
{
    public function lowStock(Product $product, Warehouse $warehouse, float $currentQty): InventoryAlert
    {
        return InventoryAlert::create([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'alert_type' => InventoryAlert::TYPE_LOW_STOCK,
            'severity' => $currentQty <= 0
                ? InventoryAlert::SEVERITY_CRITICAL
                : InventoryAlert::SEVERITY_WARNING,
            'message' => "Stock for \"{$product->name}\" at \"{$warehouse->name}\" is {$currentQty} (reorder level: {$product->reorder_level}).",
        ]);
    }

    public function negativeStock(Product $product, Warehouse $warehouse, float $currentQty): InventoryAlert
    {
        return InventoryAlert::create([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'alert_type' => InventoryAlert::TYPE_NEGATIVE_STOCK,
            'severity' => InventoryAlert::SEVERITY_CRITICAL,
            'message' => "Stock for \"{$product->name}\" at \"{$warehouse->name}\" went negative: {$currentQty}.",
        ]);
    }

    public function expiryNear(ProductBatch $batch, int $daysRemaining): InventoryAlert
    {
        $product = $batch->product;

        return InventoryAlert::create([
            'company_id' => $product->company_id ?? null,
            'product_id' => $batch->product_id,
            'warehouse_id' => $batch->warehouse_id,
            'batch_id' => $batch->id,
            'alert_type' => InventoryAlert::TYPE_EXPIRY_NEAR,
            'severity' => $daysRemaining <= 7
                ? InventoryAlert::SEVERITY_CRITICAL
                : InventoryAlert::SEVERITY_WARNING,
            'message' => "Batch \"{$batch->batch_number}\" expires in {$daysRemaining} day(s) on {$batch->expiry_date->toDateString()}.",
        ]);
    }

    public function serialConflict(int $companyId, int $productId, ?int $warehouseId, string $detail): InventoryAlert
    {
        return InventoryAlert::create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'alert_type' => InventoryAlert::TYPE_SERIAL_CONFLICT,
            'severity' => InventoryAlert::SEVERITY_CRITICAL,
            'message' => $detail,
        ]);
    }
}
