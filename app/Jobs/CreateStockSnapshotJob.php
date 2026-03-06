<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class CreateStockSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?string $date = null, public ?int $companyId = null) {}

    public function handle(): void
    {
        $params = array_filter(['--date' => $this->date ?? now()->subDay()->toDateString(), '--company' => $this->companyId]);
        Artisan::call('inventory:snapshot', $params);
    }
}
