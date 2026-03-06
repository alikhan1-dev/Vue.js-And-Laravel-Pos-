<?php

namespace App\Events;

use App\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockMovementCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public StockMovement $movement
    ) {}
}
