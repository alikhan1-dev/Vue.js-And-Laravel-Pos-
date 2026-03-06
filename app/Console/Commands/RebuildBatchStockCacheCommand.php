<?php

namespace App\Console\Commands;

use App\Models\BatchStockCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuilds batch_stock_cache from the movement ledger.
 * Use for recovery, auditing, or after data imports.
 */
class RebuildBatchStockCacheCommand extends Command
{
    protected $signature = 'inventory:rebuild-batch-cache
                            {--company= : Optional company_id to rebuild only one tenant}';

    protected $description = 'Truncate batch_stock_cache and rebuild from stock_movements where batch_id is set.';

    public function handle(): int
    {
        if (! Schema::hasTable('batch_stock_cache')) {
            $this->error('Table batch_stock_cache does not exist. Run migrations first.');

            return self::FAILURE;
        }

        $companyId = $this->option('company') ? (int) $this->option('company') : null;

        $this->info('Rebuilding batch stock cache from movement ledger...');

        DB::transaction(function () use ($companyId): void {
            BatchStockCache::query()
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->delete();

            $companyExpr = 'COALESCE(sm.company_id, (SELECT company_id FROM products WHERE products.id = sm.product_id))';

            $query = DB::table('stock_movements as sm')
                ->whereNotNull('sm.batch_id')
                ->select([
                    DB::raw("{$companyExpr} as company_id"),
                    'sm.batch_id',
                    'sm.product_id',
                    'sm.warehouse_id',
                    DB::raw('SUM(sm.quantity) as quantity'),
                ])
                ->groupBy(DB::raw($companyExpr), 'sm.batch_id', 'sm.product_id', 'sm.warehouse_id');

            if ($companyId !== null) {
                $query->where(function ($q) use ($companyId): void {
                    $q->where('sm.company_id', $companyId)
                        ->orWhereExists(function ($sub) use ($companyId): void {
                            $sub->select(DB::raw(1))
                                ->from('products')
                                ->whereColumn('products.id', 'sm.product_id')
                                ->where('products.company_id', $companyId);
                        });
                });
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                BatchStockCache::create([
                    'company_id' => $row->company_id,
                    'batch_id' => $row->batch_id,
                    'product_id' => $row->product_id,
                    'warehouse_id' => $row->warehouse_id,
                    'quantity' => $row->quantity,
                ]);
            }

            $this->info("Inserted {$rows->count()} batch cache rows.");
        });

        $this->info('Batch stock cache rebuilt successfully.');

        return self::SUCCESS;
    }
}
