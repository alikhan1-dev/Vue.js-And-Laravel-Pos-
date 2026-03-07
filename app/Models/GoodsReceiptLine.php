<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Goods receipt line. Links receipt to purchase line and quantity received.
 */
class GoodsReceiptLine extends Model
{
    protected $table = 'goods_receipt_lines';

    protected $fillable = [
        'goods_receipt_id',
        'purchase_line_id',
        'product_id',
        'quantity_received',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
