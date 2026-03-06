<?php

namespace App\Jobs;

use App\Events\LowStockDetected;
use App\Models\InventoryAlert;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued job to process low-stock and other inventory alerts (notifications, webhooks, etc.).
 * Dispatch from event listeners to keep HTTP response fast.
 */
class ProcessInventoryAlertJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $alertType,
        public array $payload
    ) {}

    public function handle(InventoryAlertService $alertService): void
    {
        if ($this->alertType === 'low_stock' && isset($this->payload['product_id'], $this->payload['warehouse_id'], $this->payload['current_qty'])) {
            $product = Product::withoutGlobalScope('company')->find($this->payload['product_id']);
            $warehouse = Warehouse::find($this->payload['warehouse_id']);
            if ($product && $warehouse) {
                $alertService->lowStock($product, $warehouse, (float) $this->payload['current_qty']);
            }
        }
    }
}
