<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase order line. One product per line.
 */
class PurchaseLine extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'quantity',
        'received_quantity',
        'received_status',
        'unit_cost',
        'tax_id',
        'discount',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'discount' => 'decimal:4',
            'subtotal' => 'decimal:2',
        ];
    }

    /** Remaining quantity to receive: quantity − received_quantity. */
    public function getRemainingQuantityAttribute(): float
    {
        return max(0, (float) $this->quantity - (float) $this->received_quantity);
    }

    /** Recompute received_status from received_quantity vs ordered quantity. */
    public function syncReceivedStatus(): void
    {
        $remaining = $this->remaining_quantity;

        if ((float) $this->received_quantity <= 0) {
            $status = 'pending';
        } elseif ($remaining > 0) {
            $status = 'partially_received';
        } else {
            $status = 'received';
        }

        if ($this->received_status !== $status) {
            $this->update(['received_status' => $status]);
        }
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function goodsReceiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
