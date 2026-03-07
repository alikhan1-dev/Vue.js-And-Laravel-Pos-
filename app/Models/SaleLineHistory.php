<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for sale line edits on drafts. Actions: added, updated, removed.
 */
class SaleLineHistory extends Model
{
    public const ACTION_ADDED = 'added';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_REMOVED = 'removed';

    protected $table = 'sale_line_history';

    protected $fillable = [
        'sale_id',
        'sale_line_id',
        'company_id',
        'action',
        'product_id',
        'variant_id',
        'old_quantity',
        'new_quantity',
        'old_unit_price',
        'new_unit_price',
        'old_discount',
        'new_discount',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_quantity' => 'decimal:2',
            'new_quantity' => 'decimal:2',
            'old_unit_price' => 'decimal:2',
            'new_unit_price' => 'decimal:2',
            'old_discount' => 'decimal:2',
            'new_discount' => 'decimal:2',
            'changed_at' => 'datetime',
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

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
