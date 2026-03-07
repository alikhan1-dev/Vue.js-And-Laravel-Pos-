<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-rate tax breakdown for a sale. Used for VAT/GST reporting and future Tax Engine.
 */
class SaleTax extends Model
{
    protected $table = 'sale_taxes';

    protected $fillable = [
        'sale_id',
        'tax_rate_id',
        'tax_name',
        'tax_rate_percent',
        'taxable_amount',
        'tax_amount',
    ];

    protected function casts(): array
    {
        return [
            'tax_rate_percent' => 'decimal:4',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
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
