<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Materialized batch-level stock cache.
 * Updated by the StockMovementObserver whenever a movement carries a batch_id.
 * Eliminates the need for SUM(stock_movements) per batch on every read.
 */
class BatchStockCache extends Model
{
    use HasFactory;

    protected $table = 'batch_stock_cache';

    protected $fillable = [
        'company_id',
        'batch_id',
        'product_id',
        'warehouse_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
