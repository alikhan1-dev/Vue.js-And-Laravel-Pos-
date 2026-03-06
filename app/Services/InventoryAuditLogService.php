<?php

namespace App\Services;

use App\Models\InventoryAuditLog;
use App\Models\StockMovement;

class InventoryAuditLogService
{
    public function logMovementCreated(StockMovement $movement, ?float $oldQuantity, ?float $newQuantity): void
    {
        if (! $this->shouldLog()) {
            return;
        }

        InventoryAuditLog::create([
            'company_id' => $movement->company_id,
            'product_id' => $movement->product_id,
            'warehouse_id' => $movement->warehouse_id,
            'variant_id' => $movement->variant_id,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'action' => 'movement_created',
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'user_id' => $movement->created_by,
            'notes' => $movement->type?->value ?? $movement->type,
        ]);
    }

    private function shouldLog(): bool
    {
        return class_exists(InventoryAuditLog::class)
            && \Illuminate\Support\Facades\Schema::hasTable('inventory_audit_logs');
    }
}
