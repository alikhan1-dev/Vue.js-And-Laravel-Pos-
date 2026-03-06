<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class RebuildBatchStockCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?int $companyId;

    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId;
    }

    public function handle(): void
    {
        $params = $this->companyId !== null ? ['--company' => $this->companyId] : [];
        Artisan::call('inventory:rebuild-batch-cache', $params);
    }
}
