<?php

namespace App\Events;

use App\Models\ProductBatch;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BatchExpiringSoon
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ProductBatch $batch,
        public int $daysUntilExpiry
    ) {}
}
