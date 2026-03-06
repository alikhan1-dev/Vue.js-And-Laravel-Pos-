<?php

namespace App\Events;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NegativeStockDetected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Product $product,
        public Warehouse $warehouse,
        public float $quantity
    ) {}
}
