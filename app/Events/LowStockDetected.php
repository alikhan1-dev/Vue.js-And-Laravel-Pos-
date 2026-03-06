<?php

namespace App\Events;

use App\Models\StockCache;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowStockDetected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public StockCache $stockCache,
        public float $suggestedQuantity
    ) {}
}
