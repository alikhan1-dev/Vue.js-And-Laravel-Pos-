<?php

namespace App\Listeners;

use App\Events\StockMovementCreated;
use App\Events\StockTransferCompleted;
use App\Models\InventoryEvent;
use Illuminate\Support\Facades\Schema;

/**
 * Writes inventory-related events to the central inventory_events table
 * for microservices, analytics, and replay.
 */
class RecordInventoryEvent
{
    public function handle(object $event): void
    {
        if (! Schema::hasTable('inventory_events')) {
            return;
        }

        if ($event instanceof StockMovementCreated) {
            InventoryEvent::record('StockMovementCreated', [
                'movement_id' => $event->movement->id,
                'uuid' => $event->movement->uuid,
                'product_id' => $event->movement->product_id,
                'warehouse_id' => $event->movement->warehouse_id,
                'type' => $event->movement->type?->value ?? $event->movement->type,
                'quantity' => (float) $event->movement->quantity,
                'created_at' => $event->movement->created_at?->toIso8601String(),
            ]);
            return;
        }

        if ($event instanceof StockTransferCompleted) {
            $out = $event->transferOut;
            $in = $event->transferIn;
            InventoryEvent::record('StockTransferCompleted', [
                'from_warehouse_id' => $out->warehouse_id,
                'to_warehouse_id' => $in->warehouse_id,
                'product_id' => $out->product_id,
                'quantity' => abs((float) $out->quantity),
                'movement_ids' => [$out->id, $in->id],
            ]);
        }
    }
}
