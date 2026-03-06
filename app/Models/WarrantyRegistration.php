<?php

namespace App\Models;

use App\Enums\WarrantyStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarrantyRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'sale_id',
        'sale_line_id',
        'customer_id',
        'product_id',
        'quantity',
        'serial_id',
        'warranty_id',
        'start_date',
        'end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => WarrantyStatus::class,
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

    public function serial(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class, 'serial_id');
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }

    public function getIsExpiredAttribute(): bool
    {
        if (! $this->end_date) {
            return false;
        }

        return $this->end_date->isPast() || $this->status === WarrantyStatus::Expired;
    }
}

