<?php

namespace App\Console\Commands;

use App\Models\StockCache;
use App\Models\StockReservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds stock_cache from the movement ledger (stock_movements) and recomputes
 * reserved_quantity from stock_reservations. Use for recovery and auditing.
 */
class RebuildStockCacheCommand extends Command
{
    protected $signature = 'inventory:rebuild-cache
                            {--company= : Optional company_id to rebuild only one tenant}';

    protected $description = 'Truncate stock_cache and rebuild from stock_movements; recompute reserved_quantity from stock_reservations.';

    public function handle(): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;

        $this->info('Rebuilding stock cache from movement ledger...');

        DB::transaction(function () use ($companyId): void {
            StockCache::query()->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->delete();

            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
                $companyFilter = $companyId !== null ? "AND (sm.company_id = {$companyId} OR p.company_id = {$companyId})" : '';
                DB::statement("
                    INSERT INTO stock_cache (company_id, product_id, variant_id, warehouse_id, quantity, reserved_quantity, created_at, updated_at)
                    SELECT
                        COALESCE(sm.company_id, p.company_id),
                        sm.product_id,
                        sm.variant_id,
                        sm.warehouse_id,
                        SUM(sm.quantity),
                        0,
                        NOW(),
                        NOW()
                    FROM stock_movements sm
                    JOIN products p ON p.id = sm.product_id
                    WHERE 1=1 {$companyFilter}
                    GROUP BY COALESCE(sm.company_id, p.company_id), sm.product_id, sm.variant_id, sm.warehouse_id
                ");
            } else {
                $companyExpr = 'COALESCE(stock_movements.company_id, (SELECT company_id FROM products WHERE products.id = stock_movements.product_id))';
                $query = DB::table('stock_movements')
                    ->select([
                        DB::raw("{$companyExpr} as company_id"),
                        'stock_movements.product_id',
                        'stock_movements.variant_id',
                        'stock_movements.warehouse_id',
                        DB::raw('SUM(stock_movements.quantity) as quantity'),
                    ])
                    ->groupBy(DB::raw($companyExpr), 'stock_movements.product_id', 'stock_movements.variant_id', 'stock_movements.warehouse_id');

                if ($companyId !== null) {
                    $query->where(function ($q) use ($companyId): void {
                        $q->where('stock_movements.company_id', $companyId)
                            ->orWhereExists(function ($sub) use ($companyId): void {
                                $sub->select(DB::raw(1))
                                    ->from('products')
                                    ->whereColumn('products.id', 'stock_movements.product_id')
                                    ->where('products.company_id', $companyId);
                            });
                    });
                }

                $rows = $query->get();

                foreach ($rows as $row) {
                    StockCache::create([
                        'company_id' => $row->company_id,
                        'product_id' => $row->product_id,
                        'variant_id' => $row->variant_id,
                        'warehouse_id' => $row->warehouse_id,
                        'quantity' => $row->quantity,
                        'reserved_quantity' => 0,
                    ]);
                }
            }

            $this->recomputeReservedQuantities($companyId);
        });

        $this->info('Stock cache rebuilt successfully.');

        return self::SUCCESS;
    }

    private function recomputeReservedQuantities(?int $companyId): void
    {
            $reserved = StockReservation::query()
            ->where('status', 'active')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('company_id, product_id, variant_id, warehouse_id, SUM(quantity) as total_reserved')
            ->groupBy('company_id', 'product_id', 'variant_id', 'warehouse_id')
            ->get();

        foreach ($reserved as $row) {
            StockCache::where('company_id', $row->company_id)
                ->where('product_id', $row->product_id)
                ->where('warehouse_id', $row->warehouse_id)
                ->where('variant_id', $row->variant_id)
                ->update(['reserved_quantity' => $row->total_reserved]);
        }
    }
}
