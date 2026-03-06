<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Product belongs to a Company (tenant). Stock is warehouse-level and
 * calculated from StockMovement records (movement-based design).
 * All queries are tenant-aware via global scope.
 */
class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'sku',
        'barcode',
        'description',
        'unit_price',
        'uom',
        'is_active',
        'category_id',
        'brand_id',
        'unit_id',
        'type',
        'cost_price',
        'average_cost',
        'selling_price',
        'track_stock',
        'track_serial',
        'track_batch',
        'allow_negative_stock',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'cost_price' => 'decimal:4',
            'average_cost' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'is_active' => 'boolean',
            'track_stock' => 'boolean',
            'track_serial' => 'boolean',
            'track_batch' => 'boolean',
            'allow_negative_stock' => 'boolean',
        ];
    }

    /**
     * Tenant isolation: filter products by the authenticated user's company_id.
     * Disabled in console (seeders, tinker).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            if (app()->runningInConsole()) {
                return;
            }

            if (auth()->check()) {
                $builder->where('company_id', auth()->user()->company_id);
            }
        });

        static::deleting(function (Product $product): void {
            app(\App\Services\InventoryDeletionGuard::class)->ensureProductCanBeDeleted($product);
        });

        static::forceDeleting(function (Product $product): void {
            app(\App\Services\InventoryDeletionGuard::class)->ensureProductCanBeDeleted($product);
        });
    }

    /** Product belongs to one Company. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    /**
     * Components that make up this product when it is sold as a bundle/kit.
     */
    public function bundleComponents(): HasMany
    {
        return $this->hasMany(ProductBundle::class, 'bundle_product_id');
    }

    /**
     * Bundles in which this product participates as a component.
     */
    public function bundledIn(): HasMany
    {
        return $this->hasMany(ProductBundle::class, 'component_product_id');
    }

    /** Product has many StockMovements (audit-ready, movement-based stock). */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Current stock for this product in a given warehouse.
     * Stock = SUM(*_in) − SUM(*_out) from all movements in that warehouse.
     */
    public function currentStock(int $warehouse_id): float
    {
        $in = implode("','", StockMovementType::stockInValues());
        $out = implode("','", StockMovementType::stockOutValues());

        $sum = $this->stockMovements()
            ->where('warehouse_id', $warehouse_id)
            ->selectRaw(
                "SUM(CASE WHEN type IN ('{$in}') THEN quantity WHEN type IN ('{$out}') THEN -quantity ELSE 0 END) as total"
            )
            ->value('total');

        return (float) ($sum ?? 0);
    }

    /**
     * Multi-warehouse reporting: stock for multiple warehouses in one query.
     * Returns a collection keyed by warehouse_id with quantity (float).
     */
    public function stockByWarehouses(Collection|array $warehouseIds): Collection
    {
        $ids = $warehouseIds instanceof Collection
            ? $warehouseIds->toArray()
            : array_values($warehouseIds);

        if (empty($ids)) {
            return collect();
        }

        $in = implode("','", StockMovementType::stockInValues());
        $out = implode("','", StockMovementType::stockOutValues());

        $rows = $this->stockMovements()
            ->whereIn('warehouse_id', $ids)
            ->selectRaw("warehouse_id, SUM(CASE WHEN type IN ('{$in}') THEN quantity WHEN type IN ('{$out}') THEN -quantity ELSE 0 END) as total")
            ->groupBy('warehouse_id')
            ->get();

        return $rows->mapWithKeys(fn ($row) => [(int) $row->warehouse_id => (float) ($row->total ?? 0)]);
    }

    /**
     * Cached stock for one warehouse (single row read; use for heavy POS).
     */
    public function currentStockCached(int $warehouse_id): float
    {
        return (float) \App\Models\StockCache::where('product_id', $this->id)
            ->where('warehouse_id', $warehouse_id)
            ->sum('quantity');
    }

    /**
     * Cached stock for multiple warehouses (one query; use for reporting/API).
     */
    public function stockByWarehousesCached(Collection|array $warehouseIds): Collection
    {
        $ids = $warehouseIds instanceof Collection
            ? $warehouseIds->toArray()
            : array_values($warehouseIds);

        if (empty($ids)) {
            return collect();
        }

        $rows = \App\Models\StockCache::where('product_id', $this->id)
            ->whereIn('warehouse_id', $ids)
            ->selectRaw('warehouse_id, SUM(quantity) as total')
            ->groupBy('warehouse_id')
            ->get();

        return $rows->mapWithKeys(fn ($row) => [(int) $row->warehouse_id => (float) ($row->total ?? 0)]);
    }
}
