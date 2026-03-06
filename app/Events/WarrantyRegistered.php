<?php

namespace App\Events;

use App\Models\WarrantyRegistration;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarrantyRegistered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public WarrantyRegistration $registration
    ) {}
}
