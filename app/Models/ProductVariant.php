<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'cost_price',
        'selling_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (ProductVariant $variant): void {
            app(\App\Services\InventoryDeletionGuard::class)->ensureVariantCanBeDeleted($variant);
        });

        static::forceDeleting(function (ProductVariant $variant): void {
            app(\App\Services\InventoryDeletionGuard::class)->ensureVariantCanBeDeleted($variant);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    public function stockCaches(): HasMany
    {
        return $this->hasMany(StockCache::class, 'variant_id');
    }
}
