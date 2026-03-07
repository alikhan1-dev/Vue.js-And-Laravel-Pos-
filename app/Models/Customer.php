<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Customer master data. Tenant-scoped by company_id.
 * Used for POS lookup, CRM, loyalty, credit sales. Soft-deleted.
 */
class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'tax_number',
        'address',
        'city',
        'country',
        'loyalty_points',
        'credit_limit',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'loyalty_points' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'status' => CustomerStatus::class,
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function billingAddress(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->where('type', 'billing');
    }

    public function shippingAddress(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->where('type', 'shipping');
    }
}
