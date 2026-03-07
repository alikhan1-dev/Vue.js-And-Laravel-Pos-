<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sale-level discount (percentage, fixed, promotion, coupon, manual).
 */
class SaleDiscount extends Model
{
    protected $fillable = [
        'sale_id',
        'company_id',
        'type',
        'value',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'type' => DiscountType::class,
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            if (app()->runningInConsole()) {
                return;
            }
            if (auth()->check()) {
                $builder->whereHas('sale', fn (Builder $q) => $q->where('company_id', auth()->user()->company_id));
            }
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
