<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Enums\SaleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


/**
 * Sale (or quotation / return) belongs to company, branch, warehouse.
 * Tenant-aware; stock movements created for sale/return types.
 * customer_id is reserved for future credit/loyalty integration (optional).
 */
class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'number',
        'company_id',
        'branch_id',
        'warehouse_id',
        'customer_id',
        'type',
        'status',
        'total',
        'paid_amount',
        'due_amount',
        'currency',
        'exchange_rate',
        'created_by',
        'return_for_sale_id',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'type' => SaleType::class,
            'status' => SaleStatus::class,
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    public function returnForSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'return_for_sale_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(SaleAuditLog::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
