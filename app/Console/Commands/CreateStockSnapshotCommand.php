<?php

namespace App\Console\Commands;

use App\Models\StockCache;
use App\Models\StockSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Creates or updates daily stock snapshots from stock_cache for fast analytics and trend reports.
 */
class CreateStockSnapshotCommand extends Command
{
    protected $signature = 'inventory:snapshot
                            {--date= : Snapshot date (Y-m-d). Default: yesterday}
                            {--company= : Optional company_id to snapshot only one tenant}';

    protected $description = 'Create daily stock snapshot from stock_cache for the given date.';

    public function handle(): int
    {
        if (! Schema::hasTable('stock_snapshots')) {
            $this->error('Table stock_snapshots does not exist. Run migrations first.');

            return self::FAILURE;
        }

        $date = $this->option('date') ? $this->option('date') : now()->subDay()->toDateString();
        $companyId = $this->option('company') ? (int) $this->option('company') : null;

        $this->info("Creating stock snapshot for date: {$date}");

        $query = StockCache::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));

        $count = 0;
        $query->chunk(500, function ($rows) use ($date, &$count): void {
            foreach ($rows as $row) {
                StockSnapshot::updateOrCreate(
                    [
                        'snapshot_date' => $date,
                        'product_id' => $row->product_id,
                        'warehouse_id' => $row->warehouse_id,
                        'variant_id' => $row->variant_id,
                    ],
                    [
                        'company_id' => $row->company_id,
                        'quantity' => $row->quantity,
                    ]
                );
                $count++;
            }
        });

        $this->info("Snapshot created: {$count} rows for {$date}.");

        return self::SUCCESS;
    }
}
