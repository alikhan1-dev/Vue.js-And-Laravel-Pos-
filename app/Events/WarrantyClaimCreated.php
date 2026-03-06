<?php

namespace App\Events;

use App\Models\WarrantyClaim;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarrantyClaimCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public WarrantyClaim $claim
    ) {}
}
