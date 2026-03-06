<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily (or periodic) stock snapshot for fast analytics and trend reports
 * without scanning the full movement ledger.
 */
class StockSnapshot extends Model
{
    use HasFactory;

    protected $table = 'stock_snapshots';

    protected $fillable = [
        'company_id',
        'snapshot_date',
        'product_id',
        'warehouse_id',
        'variant_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'quantity' => 'decimal:4',
        ];
    }

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
}
