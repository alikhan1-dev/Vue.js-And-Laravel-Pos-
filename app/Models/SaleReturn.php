<?php

namespace App\Models;

use App\Enums\SaleReturnStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Return header for a sale. Tracks refund amount and status.
 * When completed, stock is restored via ReturnIn movements.
 */
class SaleReturn extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sale_id',
        'company_id',
        'branch_id',
        'warehouse_id',
        'customer_id',
        'return_number',
        'refund_amount',
        'status',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'refund_amount' => 'decimal:2',
            'status' => SaleReturnStatus::class,
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class, 'sale_return_id');
    }
}
