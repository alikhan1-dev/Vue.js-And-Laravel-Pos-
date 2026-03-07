<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer address (billing/shipping). Multiple per customer.
 */
class CustomerAddress extends Model
{
    protected $fillable = [
        'customer_id',
        'company_id',
        'type',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            if (app()->runningInConsole()) {
                return;
            }
            if (auth()->check()) {
                $builder->whereHas('customer', fn (Builder $q) => $q->where('company_id', auth()->user()->company_id));
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
