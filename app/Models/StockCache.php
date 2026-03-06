<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached quantity per product/warehouse for fast reads (heavy POS).
 * Updated by StockMovementObserver; source of truth remains stock_movements.
 */
class StockCache extends Model
{
    protected $table = 'stock_cache';

    protected $fillable = [
        'company_id',
        'product_id',
        'warehouse_id',
        'variant_id',
        'quantity',
        'reserved_quantity',
        'reorder_level',
        'reorder_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'reserved_quantity' => 'decimal:4',
            'reorder_level' => 'decimal:2',
            'reorder_quantity' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * Available quantity = quantity - reserved_quantity.
     */
    public function getAvailableQuantityAttribute(): float
    {
        return (float) $this->quantity - (float) $this->reserved_quantity;
    }
}
