<?php

namespace App\Events;

use App\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockTransferCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public StockMovement $transferOut,
        public StockMovement $transferIn
    ) {}
}
