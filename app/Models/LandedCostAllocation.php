<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores the actual allocation result of a landed cost line to a goods receipt line.
 * Enables auditing and adjusting inventory valuation per receipt line.
 */
class LandedCostAllocation extends Model
{
    protected $fillable = [
        'landed_cost_line_id',
        'goods_receipt_line_id',
        'allocated_amount',
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:2',
        ];
    }

    public function landedCostLine(): BelongsTo
    {
        return $this->belongsTo(LandedCostLine::class);
    }

    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }
}
