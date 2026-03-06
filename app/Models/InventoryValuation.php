<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryValuation extends Model
{
    protected $table = 'inventory_valuations';

    protected $fillable = [
        'company_id',
        'product_id',
        'warehouse_id',
        'variant_id',
        'valuation_date',
        'quantity',
        'unit_cost',
        'total_value',
    ];

    protected function casts(): array
    {
        return [
            'valuation_date' => 'date',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_value' => 'decimal:4',
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
}
