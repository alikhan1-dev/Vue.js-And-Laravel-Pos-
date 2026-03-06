<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBundle extends Model
{
    use HasFactory;

    protected $fillable = [
        'bundle_product_id',
        'component_product_id',
        'quantity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_product_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}

