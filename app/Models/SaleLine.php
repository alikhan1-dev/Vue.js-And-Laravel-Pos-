<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaleLine belongs to Sale and Product; optionally linked to StockMovement.
 * Tenant isolation via sale's company_id.
 */
class SaleLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'company_id',
        'warehouse_id',
        'product_id',
        'product_name_snapshot',
        'sku_snapshot',
        'barcode_snapshot',
        'tax_class_id_snapshot',
        'variant_id',
        'quantity',
        'unit_price',
        'cost_price_at_sale',
        'line_total',
        'discount',
        'subtotal',
        'stock_movement_id',
        'reservation_id',
        'lot_number',
        'imei_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'cost_price_at_sale' => 'decimal:4',
            'line_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            if (app()->runningInConsole()) {
                return;
            }
            if (auth()->check()) {
                $builder->where(function (Builder $q) {
                    $q->where('company_id', auth()->user()->company_id)
                        ->orWhereHas('sale', fn (Builder $s) => $s->where('company_id', auth()->user()->company_id));
                });
            }
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class, 'reservation_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class, 'imei_id');
    }
}
