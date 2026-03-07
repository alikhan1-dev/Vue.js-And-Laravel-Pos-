<?php

namespace App\Models;

use App\Enums\SalePaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\SaleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Sale (order / quotation / return) belongs to company, branch, warehouse.
 * Tenant-aware; stock movements created for sale/return types.
 * Orders ≠ Payments: payment_status tracks unpaid/partial/paid/refunded.
 */
class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'number',
        'company_id',
        'branch_id',
        'warehouse_id',
        'customer_id',
        'type',
        'status',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'grand_total',
        'paid_amount',
        'due_amount',
        'currency',
        'exchange_rate',
        'notes',
        'created_by',
        'updated_by',
        'device_id',
        'pos_session_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',       // Deprecated: keep equal to grand_total for backward compatibility
            'grand_total' => 'decimal:2', // Canonical total
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'type' => SaleType::class,
            'status' => SaleStatus::class,
            'payment_status' => SalePaymentStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Sale $sale): void {
            if (empty($sale->uuid)) {
                $sale->uuid = (string) Str::uuid();
            }
        });

        // Only draft sales can be soft-deleted. Completed/refunded/cancelled must remain for reporting.
        static::deleting(function (Sale $sale): void {
            if ($sale->status !== SaleStatus::Draft) {
                throw new \InvalidArgumentException('Only draft sales can be deleted. Completed, refunded, or cancelled sales must be retained for reporting.');
            }
        });

        // Prevent exchange rate or currency changes on completed sales.
        static::updating(function (Sale $sale): void {
            if ($sale->getOriginal('status') === SaleStatus::Completed->value || $sale->getOriginal('status') === SaleStatus::Completed) {
                if ($sale->isDirty('exchange_rate') || $sale->isDirty('currency')) {
                    throw new \InvalidArgumentException('Exchange rate and currency cannot be changed on completed sales. Historical rates must be preserved.');
                }
            }
        });

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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(SaleDiscount::class);
    }

    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function saleTaxes(): HasMany
    {
        return $this->hasMany(SaleTax::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(SaleAuditLog::class);
    }

    public function lineHistory(): HasMany
    {
        return $this->hasMany(SaleLineHistory::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(SaleAdjustment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
